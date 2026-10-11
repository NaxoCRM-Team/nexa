const {test, expect} = require('@playwright/test');
const {spawn} = require('node:child_process');
const {randomUUID, randomBytes} = require('node:crypto');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const php = process.env.NEXA_PHP_PATH || 'php';
const baseURL = (process.env.NEXA_LIVE_URL || '').replace(/\/$/, '');
const optedIn = process.env.NEXA_AUTOMATION_ACCEPTANCE === '1';
const enabled = optedIn && process.env.NEXA_PROGRAMMABLE_ACCEPTANCE !== '1';

test('programmable email delivers granted read-only API sends to local Postal capture without replay duplicates', async ({browser, page}) => {
    test.setTimeout(180_000);
    test.skip(!optedIn || process.env.NEXA_PROGRAMMABLE_ACCEPTANCE !== '1' || process.env.NEXA_POSTAL_CAPTURE_ACCEPTANCE !== '1' || !baseURL, 'Run -Suite Programmable -LocalPostalCapture against the existing isolated Postal stack.');
    expect(['localhost','127.0.0.1']).toContain(new URL(baseURL).hostname);
    const suffix = randomBytes(6).toString('hex');
    let tenants = ['a','b'].map(label=>({id:randomUUID(),slug:`automation-acceptance-${suffix}-${label}`}));
    const call = (command, options = {}) => fixture(command,tenants,options).result;
    let anonymous, failure;
    try {
        tenants = await call('setup');
        const source = await call('programmable-setup',{capture:true});
        const other = await call('programmable-setup',{capture:true,index:1});
        await page.goto(`${baseURL}/login/`);
        await page.locator('#field-userName').fill(tenants[0].username);
        await page.locator('#field-password').fill(tenants[0].password);
        await page.locator('#login-form button[type="submit"]').click();
        await page.waitForURL(new RegExp(`/w/${tenants[0].slug}(?:/.*)?$`),{timeout:30_000});
        await page.goto(`${baseURL}/w/${tenants[0].slug}/NexaWorkflows`);
        await expect(page.getByRole('button',{name:'New workflow',exact:true})).toBeEnabled({timeout:30_000});
        for (const action of ['submit','approve']) {
            expect((await api(page,'post',`Nexa/marketing-emails/${source.sourceId}/transition`,{action})).ok).toBe(true);
        }
        anonymous = await browser.newContext();
        const reference = 'CAPTURE-'+randomBytes(8).toString('hex');
        const body = {contactId:tenants[0].contactId,requestKey:'capture-'+randomUUID(),sourceVersion:1,parameters:{reference,message:'Actual local capture'}};
        const send = (payload=body, sourceId=source.sourceId, method='post') => anonymous.request[method](`${baseURL}/api/v1/Nexa/marketing-emails/${sourceId}/${method==='post'?'reusable-send':''}`,{data:payload,headers:{'X-Api-Key':source.apiKey}});
        expect((await send()).status()).toBe(403);
        expect((await call('programmable-inspect')).requests).toHaveLength(0);
        const grantRoute = `Nexa/marketing-emails/${source.sourceId}/integration-grants`;
        expect((await api(page,'post',grantRoute,{action:'grant',userId:source.apiUserId,sourceVersion:1,hours:1})).ok).toBe(true);
        expect([403,404]).toContain((await send(body,other.sourceId)).status());
        const edit = await anonymous.request.put(`${baseURL}/api/v1/Nexa/marketing-emails/${source.sourceId}`,{data:{name:'Disallowed edit'},headers:{'X-Api-Key':source.apiKey}});
        expect(edit.status()).toBe(403);
        const responses = await Promise.all([send(),send()]);
        for (const response of responses) expect(response.status()).toBe(200);
        const accepted = await responses[0].json();
        expect((await responses[1].json()).queueItemId).toBe(accepted.queueItemId);
        const secondResponse = await send({...body,requestKey:'capture-'+randomUUID()});
        expect(secondResponse.status()).toBe(200);
        const secondSend = await secondResponse.json();
        expect(secondSend.queueItemId).not.toBe(accepted.queueItemId);
        expect((await api(page,'post',grantRoute,{action:'revoke',userId:source.apiUserId,sourceVersion:1})).ok).toBe(true);
        expect((await send()).status()).toBe(403);
        expect((await send({...body,requestKey:'revoked-'+randomUUID()})).status()).toBe(403);
        const before = await call('programmable-inspect');
        expect(before.requests).toHaveLength(2);
        expect(before.queues.every(queue=>queue.status==='Pending')).toBe(true);
        await call('programmable-capture-worker',{index:1});
        expect((await call('programmable-inspect')).queues).toEqual(before.queues);
        await call('programmable-capture-worker');
        const sent = await call('programmable-inspect');
        expect(sent.queues.every(queue=>queue.status==='Sent')).toBe(true);
        expect(sent.messages).toHaveLength(2);
        expect(sent.messages.every(message=>message.status==='submitted' && message.provider_message_id && Number(message.attempt_count)===1)).toBe(true);
        const subject = 'Acceptance '+reference;
        let captured = [];
        await expect.poll(async () => {
            const response = await anonymous.request.get('http://localhost:8026/api/v1/messages');
            expect(response.ok()).toBe(true);
            captured = (await response.json()).messages.filter(message=>message.Subject===subject);
            return captured.length;
        },{timeout:60_000,intervals:[500,1000,2000]}).toBe(2);
        for (const message of captured) {
            const detail = await (await anonymous.request.get(`http://localhost:8026/api/v1/message/${encodeURIComponent(message.ID)}`)).json();
            expect(detail.To[0].Address).toBe(tenants[0].slug+'-recipient@example.test');
            expect(detail.From.Address).toBe('newsletter@nexa.local.test');
            expect(detail.HTML).toContain('Actual local capture Acceptance');
            expect(detail.HTML).not.toContain('[[nexa:');
            expect(detail.HTML).not.toContain('{Contact.firstName}');
            expect(detail.HTML).toContain('unsubscribe');
            expect(detail.Text).toContain('Actual local capture Acceptance');
            const raw = await (await anonymous.request.get(`http://localhost:8026/api/v1/message/${encodeURIComponent(message.ID)}/raw`)).text();
            expect([accepted.queueItemId,secondSend.queueItemId].some(id=>raw.includes(`X-Nexa-Queue-Item: ${id}`))).toBe(true);
        }
        await call('programmable-capture-worker');
        expect((await call('programmable-inspect')).queues).toEqual(sent.queues);
        expect((await call('programmable-inspect')).messages).toEqual(sent.messages);
        const finalCapture = await (await anonymous.request.get('http://localhost:8026/api/v1/messages')).json();
        expect(finalCapture.messages.filter(message=>message.Subject===subject)).toHaveLength(2);
    } catch (error) {failure=error; throw error;}
    finally {
        if (anonymous) await anonymous.close();
        try {await call('cleanup');}
        catch (error) {if (failure) throw new AggregateError([failure,error],'Postal API acceptance and cleanup failed.'); throw error;}
    }
});

test('programmable email authenticates real HTTP, preserves intentional send keys and rechecks worker consent', async ({browser, page}) => {
    test.setTimeout(180_000);
    test.skip(!optedIn || process.env.NEXA_PROGRAMMABLE_ACCEPTANCE !== '1' || !baseURL, 'Run the local acceptance runner with -Suite Programmable.');
    expect(['localhost','127.0.0.1']).toContain(new URL(baseURL).hostname);
    const suffix = randomBytes(6).toString('hex');
    let tenants = ['a','b'].map(label => ({id:randomUUID(),slug:`automation-acceptance-${suffix}-${label}`}));
    const call = (command, options = {}) => fixture(command, tenants, options).result;
    let secondContext, failure;
    const login = async (target, tenant) => {
        await target.goto(`${baseURL}/login/`);
        await target.locator('#field-userName').fill(tenant.username);
        await target.locator('#field-password').fill(tenant.password);
        await target.locator('#login-form button[type="submit"]').click();
        await target.waitForURL(new RegExp(`/w/${tenant.slug}(?:/.*)?$`),{timeout:30_000});
        await target.goto(`${baseURL}/w/${tenant.slug}/NexaWorkflows`);
        await expect(target.getByRole('button',{name:'New workflow',exact:true})).toBeEnabled({timeout:30_000});
    };
    const success = async (target, method, url, body) => {
        const response = await api(target, method, url, body);
        expect(response.ok,`${method} ${url}: HTTP ${response.status || 'success'} ${response.reason || ''}`).toBe(true);
        return response.value;
    };
    try {
        tenants = await call('setup');
        const sources = [];
        for (let index=0; index<2; index++) sources.push(await call('programmable-setup',{index}));
        await login(page,tenants[0]);
        secondContext = await browser.newContext();
        const second = await secondContext.newPage();
        await login(second,tenants[1]);
        for (const [index, target] of [[0,page],[1,second]]) {
            for (const action of ['submit','approve']) await success(target,'post',`Nexa/marketing-emails/${sources[index].sourceId}/transition`,{action});
        }
        const body = (index, key, parameters = {reference:'ACCEPT-001',message:'Synthetic safe and bounded'}) => ({contactId:tenants[index].contactId,requestKey:key,sourceVersion:1,parameters});
        const route = index => `Nexa/marketing-emails/${sources[index].sourceId}/reusable-send`;
        const anonymous = await browser.newContext();
        try {
            const response = await anonymous.request.post(`${baseURL}/api/v1/${route(0)}`,{data:body(0,'anonymous-request')});
            expect([401,403]).toContain(response.status());
        } finally {await anonymous.close();}
        for (const invalid of [
            {...body(0,'missing-parameters'),parameters:{}},
            {...body(0,'oversized-parameter'),parameters:{reference:'x'.repeat(41),message:'Valid'}},
            {...body(0,'extra-parameter'),parameters:{reference:'OK',message:'Valid',unexpected:'No'}},
            {...body(0,'html-parameter'),parameters:{reference:'OK',message:'<script>invalid</script>'}},
            {...body(0,'client-scope'),tenantId:tenants[1].id},
            {...body(0,'foreign-contact'),contactId:tenants[1].contactId},
            {...body(0,'stale-version'),sourceVersion:2},
        ]) {
            const response = await api(page,'post',route(0),invalid);
            expect(response.ok).toBe(false);
            expect([400,403,404]).toContain(response.status);
        }
        expect((await call('programmable-inspect')).requests).toHaveLength(0);
        expect((await call('programmable-inspect')).queues).toHaveLength(0);
        const foreign = await api(second,'post',route(0),body(1,'foreign-source'));
        expect(foreign.ok).toBe(false);
        expect([403,404]).toContain(foreign.status);
        const key = 'intentional-'+randomUUID();
        const replies = await Promise.all([success(page,'post',route(0),body(0,key)),success(page,'post',route(0),body(0,key))]);
        expect(replies[0].queueItemId).toBeTruthy();
        expect(replies[1].queueItemId).toBe(replies[0].queueItemId);
        const replay = await success(page,'post',route(0),body(0,key));
        expect(replay.queueItemId).toBe(replies[0].queueItemId);
        const conflict = await api(page,'post',route(0),body(0,key,{reference:'CHANGED',message:'Different parameters'}));
        expect(conflict.ok).toBe(false);
        expect(conflict.status).toBe(400);
        const intentional = await success(page,'post',route(0),body(0,'intentional-'+randomUUID()));
        expect(intentional.queueItemId).not.toBe(replay.queueItemId);
        const independent = await success(second,'post',route(1),body(1,key));
        expect(independent.queueItemId).not.toBe(replay.queueItemId);
        const firstState = await call('programmable-inspect');
        expect(firstState.requests).toHaveLength(2);
        expect(firstState.queues).toHaveLength(2);
        expect(firstState.queues.every(queue=>queue.status==='Pending')).toBe(true);
        expect(firstState.copies).toHaveLength(2);
        for (const copy of firstState.copies) {
            expect(copy.subject).toBe('Acceptance ACCEPT-001');
            expect(copy.body).toContain('Synthetic safe and bounded');
            expect(copy.body).not.toContain('[[nexa:');
        }
        await call('mutate',{change:'user-regular'});
        const downgraded = await api(page,'post',route(0),body(0,'downgraded-'+randomUUID()));
        expect(downgraded.ok).toBe(false);
        expect([403,404]).toContain(downgraded.status);
        expect((await call('programmable-inspect')).requests).toHaveLength(2);
        await call('mutate',{change:'user-active'});
        await call('programmable-revoke',{index:1});
        await call('programmable-worker',{index:1});
        expect((await call('programmable-inspect')).queues).toEqual(firstState.queues);
        await call('programmable-revoke');
        await call('programmable-worker');
        const stopped = await call('programmable-inspect');
        expect(stopped.queues.every(queue=>queue.status==='Failed')).toBe(true);
        expect(stopped.submissions).toBe(0);
        await call('programmable-worker');
        expect((await call('programmable-inspect')).queues).toEqual(stopped.queues);
        const afterRevocation = await api(page,'post',route(0),body(0,'revoked-'+randomUUID()));
        expect(afterRevocation.ok).toBe(false);
        expect(afterRevocation.status).toBe(400);
        expect((await call('programmable-inspect')).requests).toHaveLength(2);
    } catch (error) {failure=error; throw error;}
    finally {
        if (secondContext) await secondContext.close();
        try {await call('cleanup');}
        catch (error) {if (failure) throw new AggregateError([failure,error],'Programmable acceptance and cleanup failed.'); throw error;}
    }
});

// No traces: login credentials and native webhook signing material must not become artifacts.
test.use({trace: 'off', screenshot: 'off'});

function fixture(command, tenants, options = {}) {
    const child = spawn(php, ['-d','display_errors=stderr','-d','log_errors=0',path.join(root, 'tests/tenant/AutomationAcceptanceFixture.php')], {
        cwd: root, env: {...process.env, NEXA_AUTOMATION_ACCEPTANCE: '1'}, windowsHide: true,
        stdio: ['pipe', 'pipe', 'pipe'],
    });
    let output = '', errors = '', readyResolve, readyReject;
    const ready = new Promise((resolve, reject) => {readyResolve = resolve; readyReject = reject;});
    const result = new Promise((resolve, reject) => {
        const timeout = setTimeout(() => {child.kill(); reject(new Error(`Fixture ${command} timed out.`));}, 45_000);
        child.stdout.on('data', chunk => {
            output += chunk.toString();
            if (output.startsWith('READY\n')) readyResolve();
        });
        child.stderr.on('data', chunk => {errors += chunk.toString();});
        child.on('error', error => {clearTimeout(timeout); readyReject(error); reject(error);});
        child.on('close', code => {
            clearTimeout(timeout);
            if (code !== 0) {
                // PHP diagnostics never include the input containing temporary credentials.
                const error = new Error(`Fixture ${command} failed (${code}): ${errors || output}`);
                readyReject(error); reject(error); return;
            }
            try {resolve(JSON.parse(output.replace(/^READY\r?\n/, '')));}
            catch {reject(new Error(`Fixture ${command} did not return JSON.`));}
        });
    });
    ready.catch(() => {});
    result.catch(() => {});
    child.stdin.write(JSON.stringify({command, tenants, ...options}) + '\n');
    if (!options.barrier) child.stdin.end();
    return {ready, result, release: () => child.stdin.end('GO\n')};
}

async function concurrent(tenants, operations) {
    const children = operations.map(({command, ...options}) => fixture(command, tenants, {...options, barrier: true}));
    try {
        await Promise.all(children.map(child => child.ready));
    } finally {
        for (const child of children) child.release();
    }
    return Promise.all(children.map(child => child.result));
}

async function api(page, method, url, data) {
    return page.evaluate(async ({method, url, data}) => {
        try {
            const value = await Espo.Ajax[`${method}Request`](url, data);
            return {ok: true, value};
        } catch (error) {return {ok: false, status: error.status || error.statusCode || null, reason: error.getResponseHeader?.('X-Status-Reason') || ''};}
    }, {method, url, data});
}

test('authenticated automation survives competing workers, cancellation and revoked permissions', async ({browser, page}, testInfo) => {
    test.setTimeout(240_000);
    page.setDefaultTimeout(20_000);
    page.setDefaultNavigationTimeout(30_000);
    page.on('pageerror',error=>console.info('Live application error: '+error.message));
    test.skip(!enabled || !baseURL, 'Set NEXA_AUTOMATION_ACCEPTANCE=1, NEXA_LIVE_URL and NEXA_PHP_PATH for committed local fixtures.');
    expect(['localhost', '127.0.0.1']).toContain(new URL(baseURL).hostname);
    const suffix = randomBytes(6).toString('hex');
    let tenants = ['a','b'].map(label => ({id: randomUUID(), slug: `automation-acceptance-${suffix}-${label}`}));
    const callFixture = (command, options = {}) => fixture(command, tenants, options).result;
    let secondContext, acceptanceError;
    const login = async (target, tenant) => {
        target.setDefaultTimeout(20_000);
        target.setDefaultNavigationTimeout(30_000);
        await target.goto(`${baseURL}/login/`);
        await target.locator('#field-userName').fill(tenant.username);
        await target.locator('#field-password').fill(tenant.password);
        await target.locator('#login-form button[type="submit"]').click();
        await target.waitForURL(new RegExp(`/w/${tenant.slug}(?:/.*)?$`), {timeout: 30_000});
        await target.goto(`${baseURL}/w/${tenant.slug}/NexaWorkflows`);
        await expect(target.getByRole('heading', {name:'Workflows', exact:true})).toBeVisible({timeout:30_000});
        await expect(target.getByRole('button', {name:'New workflow', exact:true})).toBeEnabled();
    };
    const request = async (method, url, data, target = page) => {
        const response = await api(target, method, url, data);
        expect(response.ok, `${method} ${url} must succeed`).toBe(true);
        return response.value;
    };
    const graph = (action = 'score.increase', settings = {amount:1}, trigger = 'manual') => ({
        schemaVersion:1, trigger:{event:trigger, reenrollment:'never'}, entry:'action', nodes:[
            {id:'action', type:'action', action, settings, maxAttempts:2, retrySeconds:60, next:'end'},
            {id:'end', type:'end'},
        ],
    });
    const create = async (definition, label) => {
        const draft = await request('post','Nexa/workflows',{name:`Acceptance ${label} ${suffix}`, definition});
        await request('post',`Nexa/workflows/${draft.id}/publish`,{expectedRevision:draft.draftRevision});
        return draft.id;
    };
    const enroll = (workflowId, index = 0, target = page, trigger = 'enroll') => request('post',`Nexa/workflows/${workflowId}/${trigger}`,{contactId:tenants[index].contactId,requestKey:randomUUID()},target);
    const inspect = workflowId => callFixture('inspect',{workflowId});
    try {
        tenants = await callFixture('setup');
        console.info('Acceptance: temporary native tenants committed; logging in.');
        await login(page, tenants[0]);
        console.info('Acceptance: real workflow builder loaded.');

        // Real rendered builder -> authenticated native HTTP -> independent system workers.
        await page.getByRole('button',{name:'New workflow',exact:true}).click();
        await expect(page.getByRole('textbox',{name:'Workflow name'})).toBeVisible();
        await page.getByRole('textbox',{name:'Workflow name'}).fill(`Acceptance canvas ${suffix}`);
        await page.getByRole('combobox',{name:'New step type'}).selectOption('score.increase');
        await page.getByRole('button',{name:'Add step',exact:true}).click();
        await page.getByRole('spinbutton',{name:'Score adjustment'}).fill('7');
        await page.getByRole('spinbutton',{name:'Score adjustment'}).press('Tab');
        const saveResponse = page.waitForResponse(response => response.request().method()==='POST' && /\/Nexa\/workflows$/.test(new URL(response.url()).pathname));
        await page.getByRole('button',{name:'Save draft',exact:true}).click();
        const saved = await saveResponse;
        expect(saved.ok()).toBe(true);
        const draft = await saved.json();
        await expect(page.getByRole('button',{name:'Publish',exact:true})).toBeEnabled();
        await page.getByRole('button',{name:'Publish',exact:true}).click();
        await expect(page.getByRole('button',{name:'Enroll Contact',exact:true})).toBeEnabled();
        const run = await enroll(draft.id);
        await concurrent(tenants,[{command:'tick'},{command:'tick'}]);
        await callFixture('tick',{ticks:2});
        let evidence = await inspect(draft.id);
        expect(evidence.score).toBe(17);
        expect(evidence.runs.find(row=>row.id===run.id).status).toBe('completed');
        expect(evidence.events.filter(event=>event.event_type==='step.completed' && event.node_id===evidence.events.find(event=>event.event_type==='step.attempted')?.node_id)).toHaveLength(1);
        await callFixture('tick',{ticks:2});
        expect((await inspect(draft.id)).score).toBe(17);
        await page.getByRole('button',{name:'Refresh history',exact:true}).click();
        await expect(page.locator('[data-history]')).toContainText('Completed');
        console.info('Acceptance: authenticated builder/API/competing-worker scenario passed.');
        await page.screenshot({path:testInfo.outputPath('authenticated-workflow.png')});

        const intakeId = await create(graph('tags.add',{tag:'API acceptance'},'api.event'),'API intake');
        const key = randomUUID();
        const intake = await request('post',`Nexa/workflows/${intakeId}/events`,{contactId:tenants[0].contactId,requestKey:key});
        expect((await request('post',`Nexa/workflows/${intakeId}/events`,{contactId:tenants[0].contactId,requestKey:key})).id).toBe(intake.id);
        expect((await api(page,'post',`Nexa/workflows/${intakeId}/events`,{contactId:tenants[0].contactId,requestKey:randomUUID(),tenantId:tenants[1].id})).ok).toBe(false);
        await callFixture('tick',{ticks:2});
        expect((await inspect(intakeId)).runs[0].status).toBe('completed');
        const updateId=await create(graph('contact.update',{field:'department',value:'Acceptance updated'}),'native property update');
        await enroll(updateId); await callFixture('tick',{ticks:2});
        expect((await inspect(updateId)).runs[0].status).toBe('completed');
        expect((await request('get',`Contact/${tenants[0].contactId}`)).department).toBe('Acceptance updated');

        secondContext = await browser.newContext();
        const secondPage = await secondContext.newPage();
        await login(secondPage, tenants[1]);
        const foreignHistory=await api(secondPage,'get',`Nexa/workflows/${draft.id}/history`);
        expect(foreignHistory.ok).toBe(false);
        expect([403,404]).toContain(foreignHistory.status);
        expect((await api(secondPage,'post',`Nexa/workflows/${draft.id}/runs/${run.id}/cancel`,{})).ok).toBe(false);
        for(const [method,url,data] of [
            ['get',`Nexa/workflows/${draft.id}`],
            ['post',`Nexa/workflows/${draft.id}/publish`,{expectedRevision:1}],
            ['post',`Nexa/workflows/${draft.id}/control`,{status:'paused'}],
            ['put',`Nexa/workflows/${draft.id}`,{name:'Forbidden update',definition:graph(),expectedRevision:1}],
            ['post',`Nexa/workflows/${draft.id}/enroll`,{contactId:tenants[1].contactId,requestKey:randomUUID()}],
            ['post',`Nexa/workflows/${draft.id}/events`,{contactId:tenants[1].contactId,requestKey:randomUUID()}],
            ['post',`Nexa/workflows/${draft.id}/runs/${run.id}/webhook-review`,{outcome:'success',evidence:'Unauthorized delivery review'}],
        ]) {
            const forbidden=await api(secondPage,method,url,data);
            expect(forbidden.ok,`Tenant B must not use ${method} ${url}`).toBe(false);
            expect([403,404]).toContain(forbidden.status);
        }
        const tenantBId = (await request('post','Nexa/workflows',{name:`Acceptance B ${suffix}`,definition:graph()},secondPage)).id;
        await request('post',`Nexa/workflows/${tenantBId}/publish`,{expectedRevision:1},secondPage);
        await enroll(tenantBId,1,secondPage);
        await concurrent(tenants,[{command:'tick',index:0},{command:'tick',index:1}]);
        await callFixture('tick',{index:1,ticks:2});
        const otherTenant=await callFixture('inspect',{index:1,workflowId:tenantBId});
        expect(otherTenant.score).toBe(11);
        expect(otherTenant.runs[0].status).toBe('completed');
        expect((await inspect(draft.id)).score).toBe(17);
        const fairnessId=await create(graph(),'tenant fairness under held quota');
        await enroll(fairnessId);
        const heldQuota=fixture('hold-quota',tenants,{index:1,barrier:true});
        try {
            await heldQuota.ready;
            await callFixture('tick',{ticks:2});
            expect((await inspect(fairnessId)).runs[0].status).toBe('completed');
        } finally {heldQuota.release(); await heldQuota.result;}
        expect((await api(page,'post',`Nexa/workflows/${intakeId}/events`,{contactId:tenants[1].contactId,requestKey:randomUUID()})).ok).toBe(false);
        for(const field of ['leadScore','tenantId','serviceId']) {
            const denied=await request('post','Nexa/workflows',{name:`Denied property ${field} ${suffix}`,definition:graph('contact.update',{field,value:'Unauthorized'})});
            const publication=await api(page,'post',`Nexa/workflows/${denied.id}/publish`,{expectedRevision:1});
            expect(publication.ok,`Generic update cannot write ${field}`).toBe(false);
            expect([400,403]).toContain(publication.status);
        }

        // Release both OS processes only after both native connections are ready.
        for (let attempt=0;attempt<6;attempt++) {
            const id = await create(graph(),'cancellation race '+attempt);
            const enrollment = await enroll(id);
            await concurrent(tenants,[{command:'tick'},{command:'cancel',workflowId:id,runId:enrollment.id}]);
            evidence = await inspect(id);
            expect(evidence.runs[0].status).toBe('cancelled');
            const cancellation = evidence.events.findIndex(event=>event.event_type==='cancelled');
            expect(cancellation).toBeGreaterThanOrEqual(0);
            expect(evidence.events.slice(cancellation+1).filter(event=>event.event_type==='step.completed')).toHaveLength(0);
            expect(evidence.events.filter(event=>event.event_type==='step.completed').length).toBeLessThanOrEqual(1);
            const score=evidence.score;
            await callFixture('tick',{ticks:2});
            expect((await inspect(id)).score).toBe(score);
        }

        // Re-check execution identity and source availability in new processes after publication.
        for (const [change,restore] of [['user-inactive','user-active'],['user-regular','user-active'],['contact-deleted','contact-active'],['contact-other-service','contact-own-service']]) {
            const id = await create(graph(),'permission '+change);
            await enroll(id);
            const before=(await inspect(id)).score;
            await callFixture('mutate',{change});
            try {
                if (change==='user-regular') expect((await api(page,'get',`Nexa/workflows/${id}/history`)).ok).toBe(false);
                await callFixture('tick',{ticks:2});
                evidence = await inspect(id);
                expect(evidence.runs[0].status).toBe('failed');
                expect(evidence.score).toBe(before);
                expect(evidence.events.filter(event=>event.event_type==='step.completed')).toHaveLength(0);
            } finally {await callFixture('mutate',{change:restore});}
        }

        const hookGraph = graph('webhook.send',{webhookId:tenants[0].webhookId});
        const hookId = await create(hookGraph,'competing leases');
        const hookRun = await enroll(hookId);
        await callFixture('tick');
        const effectId=(await inspect(hookId)).effects[0].id;
        const claims=await concurrent(tenants,[{command:'claim',effectId},{command:'claim',effectId}]);
        const winners=claims.filter(claim=>claim.id);
        expect(winners).toHaveLength(1);
        // Cancellation cannot recall an in-flight lease; it must prohibit a second claim.
        await request('post',`Nexa/workflows/${hookId}/runs/${hookRun.id}/cancel`,{});
        expect((await callFixture('claim',{effectId})).claimed).toBe(false);
        await callFixture('finalize',{effectId,token:winners[0].token});
        evidence=await inspect(hookId);
        expect(evidence.runs[0].status).toBe('cancelled');
        expect(evidence.effects[0].status).toBe('success');
        expect(evidence.effects[0].attempts).toBe(1);

        const restartId=await create(hookGraph,'expired lease');
        await enroll(restartId); await callFixture('tick');
        const restartEffect=(await inspect(restartId)).effects[0].id;
        const originalClaim=await callFixture('claim',{effectId:restartEffect});
        expect(originalClaim.id).toBe(restartEffect);
        await callFixture('mutate',{change:'lease-expired',effectId:restartEffect});
        expect((await callFixture('claim',{effectId:restartEffect})).claimed).toBe(false);
        await callFixture('finalize',{effectId:restartEffect,token:originalClaim.token});
        evidence=await inspect(restartId);
        expect(evidence.effects[0].status).toBe('uncertain');
        expect(evidence.effects[0].attempts).toBe(1);

        const revokedId=await create(hookGraph,'webhook revoked');
        await enroll(revokedId); await callFixture('tick');
        const revokedEffect=(await inspect(revokedId)).effects[0].id;
        await callFixture('mutate',{change:'hook-inactive'});
        try {
            expect((await callFixture('claim',{effectId:revokedEffect})).claimed).toBe(false);
            evidence=await inspect(revokedId);
            expect(evidence.effects[0].status).toBe('failed');
            expect(evidence.effects[0].attempts).toBe(0);
        } finally {await callFixture('mutate',{change:'hook-active'});}

        const raceId=await create(hookGraph,'claim cancellation race');
        const raceRun=await enroll(raceId); await callFixture('tick');
        const raceEffect=(await inspect(raceId)).effects[0].id;
        const [raceClaim]=await concurrent(tenants,[{command:'claim',effectId:raceEffect},{command:'cancel',workflowId:raceId,runId:raceRun.id}]);
        if(raceClaim.id) await callFixture('finalize',{effectId:raceEffect,token:raceClaim.token});
        evidence=await inspect(raceId);
        expect(evidence.runs[0].status).toBe('cancelled');
        expect(['cancelled','success']).toContain(evidence.effects[0].status);
        expect(evidence.effects[0].attempts).toBe(raceClaim.id?1:0);
        expect((await callFixture('claim',{effectId:raceEffect})).claimed).toBe(false);

        const finalizationId=await create(hookGraph,'finalization cancellation race');
        const finalizationRun=await enroll(finalizationId); await callFixture('tick');
        const finalizationEffect=(await inspect(finalizationId)).effects[0].id;
        const finalizationClaim=await callFixture('claim',{effectId:finalizationEffect});
        expect(finalizationClaim.id).toBe(finalizationEffect);
        await concurrent(tenants,[
            {command:'cancel',workflowId:finalizationId,runId:finalizationRun.id},
            {command:'finalize',effectId:finalizationEffect,token:finalizationClaim.token},
        ]);
        evidence=await inspect(finalizationId);
        expect(evidence.runs[0].status).toBe('cancelled');
        expect(evidence.effects[0].status).toBe('success');
        expect(evidence.effects[0].attempts).toBe(1);
        expect((await callFixture('claim',{effectId:finalizationEffect})).claimed).toBe(false);

        await callFixture('mutate',{change:'concurrency-one'});
        const capacityIds=await Promise.all([create(hookGraph,'capacity one'),create(hookGraph,'capacity two')]);
        const capacityRuns=await Promise.all(capacityIds.map(id=>enroll(id)));
        await callFixture('tick');
        const capacityEffects=await Promise.all(capacityIds.map(async id=>(await inspect(id)).effects[0].id));
        const capacityClaims=await concurrent(tenants,capacityEffects.map(effectId=>({command:'claim',effectId})));
        expect(capacityClaims.filter(claim=>claim.id)).toHaveLength(1);
        for(let i=0;i<2;i++) await request('post',`Nexa/workflows/${capacityIds[i]}/runs/${capacityRuns[i].id}/cancel`,{});
        for(const claim of capacityClaims.filter(claim=>claim.id)) await callFixture('finalize',{effectId:claim.id,token:claim.token});
        for(const effectId of capacityEffects) expect((await callFixture('claim',{effectId})).claimed).toBe(false);
        console.info('Acceptance: cancellation races, two-tenant workers, revoked permissions and concurrent lease capacity passed.');
    } catch(error) {
        acceptanceError=error;
        console.info('Acceptance failure: '+error.message);
        throw error;
    } finally {
        if (secondContext) await secondContext.close();
        try {expect((await callFixture('cleanup')).cleaned).toBe(2);}
        catch(cleanupError) {
            if(acceptanceError) throw new AggregateError([acceptanceError,cleanupError],'Acceptance and cleanup failed; see the primary acceptance diagnostic.');
            throw cleanupError;
        }
    }
});

test('approved public HTTPS webhook is delivered once by the native worker', async ({page}) => {
    test.setTimeout(90_000);
    page.setDefaultTimeout(20_000);
    const approved = process.env.NEXA_AUTOMATION_WEBHOOK_URL || '';
    test.skip(!enabled || !baseURL || !approved, 'Public delivery requires an endpoint explicitly approved by its owner. No request is made without it.');
    expect(['localhost','127.0.0.1']).toContain(new URL(baseURL).hostname);
    const suffix=randomBytes(6).toString('hex');
    let tenants=['a','b'].map(label=>({id:randomUUID(),slug:`automation-acceptance-${suffix}-${label}`}));
    const local=(command,options={})=>fixture(command,tenants,options).result;
    const request=async(method,url,data)=>{
        const result=await api(page,method,url,data); expect(result.ok).toBe(true); return result.value;
    };
    try {
        tenants=await local('setup');
        await local('configure-approved-hook',{url:approved});
        await page.goto(`${baseURL}/login/`);
        await page.locator('#field-userName').fill(tenants[0].username);
        await page.locator('#field-password').fill(tenants[0].password);
        await page.locator('#login-form button[type="submit"]').click();
        await page.waitForURL(new RegExp(`/w/${tenants[0].slug}(?:/.*)?$`),{timeout:30_000});
        await page.goto(`${baseURL}/w/${tenants[0].slug}/NexaWorkflows`);
        await expect(page.getByRole('button',{name:'New workflow',exact:true})).toBeEnabled();
        const definition={schemaVersion:1,trigger:{event:'manual',reenrollment:'never'},entry:'hook',nodes:[
            {id:'hook',type:'action',action:'webhook.send',settings:{webhookId:tenants[0].webhookId},maxAttempts:2,retrySeconds:60,next:'end'},
            {id:'end',type:'end'},
        ]};
        const draft=await request('post','Nexa/workflows',{name:`Approved webhook acceptance ${suffix}`,definition});
        await request('post',`Nexa/workflows/${draft.id}/publish`,{expectedRevision:1});
        await request('post',`Nexa/workflows/${draft.id}/enroll`,{contactId:tenants[0].contactId,requestKey:randomUUID()});
        await local('tick');
        expect((await local('approved-delivery',{url:approved})).processed).toBe(1);
        let evidence=await local('inspect',{workflowId:draft.id});
        expect(evidence.effects).toHaveLength(1);
        expect(evidence.effects[0].status).toBe('success');
        expect(evidence.effects[0].attempts).toBe(1);
        expect((await local('approved-delivery',{url:approved})).processed).toBe(0);
        await local('tick',{ticks:2});
        evidence=await local('inspect',{workflowId:draft.id});
        // The webhook's normal polling interval is intentionally not shortened for the test.
        expect(['waiting','completed']).toContain(evidence.runs[0].status);
    } finally {expect((await local('cleanup')).cleaned).toBe(2);}
});
