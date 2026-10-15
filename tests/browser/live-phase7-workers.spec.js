const {test,expect}=require('@playwright/test');
const {spawn}=require('node:child_process');
const {randomUUID,randomBytes}=require('node:crypto');
const path=require('node:path');
const root=path.resolve(__dirname,'../..');
test.use({trace:'off',screenshot:'off'});

function fixture(command,tenants,options={}) {
    const child=spawn(process.env.NEXA_PHP_PATH||'php',[path.join(root,'tests/tenant/AutomationAcceptanceFixture.php')],{cwd:root,env:process.env,windowsHide:true,stdio:['pipe','pipe','pipe']});
    let out='',err='',resolveReady,rejectReady;
    const ready=new Promise((resolve,reject)=>{resolveReady=resolve;rejectReady=reject;});
    const result=new Promise((resolve,reject)=>{
        const timer=setTimeout(()=>{child.kill();const error=new Error(`Phase 7 ${command} timed out.`);rejectReady(error);reject(error);},command==='phase7-capacity'?600000:60000);
        child.stdout.on('data',data=>{out+=data;if(out.startsWith('READY\n'))resolveReady();});
        child.stderr.on('data',data=>{err+=data;if(command==='phase7-capacity')console.log(String(data).trim());});
        child.on('error',error=>{clearTimeout(timer);rejectReady(error);reject(error);});
        child.on('close',code=>{clearTimeout(timer);if(code!==0){const error=new Error(`Phase 7 ${command} failed: ${err}`);rejectReady(error);reject(error);return;}try{resolve(JSON.parse(out.replace(/^READY\r?\n/,'')));}catch{reject(new Error('Invalid Phase 7 fixture response.'));}});
    });
    ready.catch(()=>{});result.catch(()=>{});
    child.stdin.write(JSON.stringify({command,tenants,...options})+'\n');if(!options.barrier)child.stdin.end();
    return {ready,result,release:()=>child.stdin.end('GO\n')};
}
async function race(tenants,operations){
    const children=operations.map(operation=>fixture(operation.command,tenants,{...operation,barrier:true}));
    try{await Promise.all(children.map(child=>child.ready));}finally{children.forEach(child=>child.release());}
    const settled=await Promise.allSettled(children.map(child=>child.result));
    const failure=settled.find(item=>item.status==='rejected');if(failure)throw failure.reason;
    return settled.map(item=>item.value);
}

test('Global queue selection rotates busy and small tenants across independent selectors',async({},info)=>{
    test.setTimeout(180000);
    test.skip(process.env.NEXA_AUTOMATION_ACCEPTANCE!=='1','Use the explicit local acceptance runner.');
    const suffix=randomBytes(6).toString('hex');let tenants=['a','b'].map(label=>({id:randomUUID(),slug:`automation-acceptance-${suffix}-${label}`}));let failure;
    const call=(command,options={})=>fixture(command,tenants,options).result;
    try{
        tenants=await call('setup');
        await call('scoring-concurrency-setup',{contacts:40});await call('scoring-concurrency-setup',{contacts:20,index:1});
        await call('queue-setup',{count:40});await call('queue-setup',{count:10,index:1});
        expect(await call('queue-selection-failure')).toEqual({cursorUnchanged:true,transactionReleased:true});
        const started=Date.now();
        for(let round=0;round<20;round++){
            const rows=await race(tenants,[{command:'queue-select',limit:1},{command:'queue-select',limit:1}]);
            expect(rows.every(batch=>batch.length===1)).toBe(true);
            expect(new Set(rows.map(batch=>batch[0].tenantId))).toEqual(new Set(tenants.map(tenant=>tenant.id)));
            expect(rows.flat().every(row=>row.serviceId===tenants[0].serviceId)).toBe(true);
        }
        expect((await call('queue-inspect')).every(row=>row.status==='Pending')).toBe(true);
        expect(await call('queue-selection-failure')).toEqual({cursorUnchanged:true,transactionReleased:true});
        await call('queue-process',{limit:2});
        expect((await call('scoring-inspect')).evaluations).toHaveLength(10);
        expect((await call('scoring-inspect',{index:1})).evaluations).toHaveLength(10);
        const jobs=await call('queue-inspect');
        for(const tenant of tenants)expect(jobs.filter(row=>row.tenant_id===tenant.id&&row.status==='Success')).toHaveLength(1);
        await info.attach('global-queue-selection.json',{body:JSON.stringify({independentSelectors:2,rounds:20,slotsPerSelector:1,busyBacklog:40,smallBacklog:10,seconds:(Date.now()-started)/1000,scope:'Selection fairness and native execution, not a wall-clock completion SLA.'}),contentType:'application/json'});
    }catch(error){failure=error;}finally{try{await call('cleanup');}catch(error){if(!failure)failure=error;}}
    if(failure)throw failure;
});

test('Prefetched native scheduled jobs keep claim deduplication and cancellation safety',async()=>{
    test.setTimeout(120000);
    test.skip(process.env.NEXA_AUTOMATION_ACCEPTANCE!=='1','Use the explicit local acceptance runner.');
    const suffix=randomBytes(6).toString('hex');let tenants=['a','b'].map(label=>({id:randomUUID(),slug:`automation-acceptance-${suffix}-${label}`}));let failure,pending;
    const call=(command,options={})=>fixture(command,tenants,options).result;
    try{
        tenants=await call('setup');await call('scoring-concurrency-setup',{contacts:35});
        await call('queue-setup',{count:1,scheduled:true});
        await race(tenants,[{command:'queue-prefetch-process'},{command:'queue-prefetch-process'}]);
        const completed=await call('queue-inspect');expect(completed).toHaveLength(1);expect(completed[0].status).toBe('Success');
        const first=await call('scoring-inspect');expect(first.evaluations).toHaveLength(10);expect(first.evaluations.every(row=>Number(row.copies)===1)).toBe(true);
        await call('scoring-wake');
        const cancel=await call('queue-setup',{count:1,scheduled:true});
        pending=fixture('queue-prefetch-process',tenants,{barrier:true});
        await pending.ready;
        await call('queue-cancel',{jobId:cancel.ids[0]});pending.release();await pending.result;pending=null;
        const removed=(await call('queue-inspect')).find(row=>row.id===cancel.ids[0]);
        expect(Number(removed.deleted)).toBe(1);expect(removed.status).toBe('Pending');
        expect((await call('scoring-inspect')).evaluations).toEqual(first.evaluations);
    }catch(error){failure=error;}finally{
        if(pending){pending.release();await pending.result.catch(()=>{});}
        try{await call('cleanup');}catch(error){if(!failure)failure=error;}
    }
    if(failure)throw failure;
});

test('Independent Phase 7 workers enforce scoring, ABM, archive and quota boundaries',async()=>{
    test.setTimeout(180000);
    test.skip(process.env.NEXA_AUTOMATION_ACCEPTANCE!=='1','Use the explicit local Phase7Workers acceptance runner.');
    expect(['localhost','127.0.0.1']).toContain(new URL(process.env.NEXA_LIVE_URL).hostname);
    const suffix=randomBytes(6).toString('hex');let tenants=['a','b'].map(label=>({id:randomUUID(),slug:`automation-acceptance-${suffix}-${label}`}));let failure;
    const call=(command,options={})=>fixture(command,tenants,options).result;
    try{
        tenants=await call('setup');
        const alpha=await call('scoring-concurrency-setup');await call('scoring-concurrency-setup',{index:1});
        const first=await race(tenants,[{command:'scoring-tick'},{command:'scoring-tick'}]);
        expect(first.map(item=>item.processed).sort((a,b)=>a-b)).toEqual([0,10]);
        const inspect=await call('scoring-inspect');expect(inspect.evaluations).toHaveLength(10);
        expect(inspect.evaluations.every(row=>Number(row.copies)===1)).toBe(true);
        expect((await call('scoring-inspect',{index:1})).evaluations).toHaveLength(0);
        await call('scoring-wake');
        const mixed=await race(tenants,[{command:'scoring-tick'},{command:'scoring-tick'},{command:'scoring-tick',index:1}]);
        expect(mixed.slice(0,2).reduce((sum,item)=>sum+item.processed,0)).toBe(10);
        expect(mixed[2].processed).toBe(10);
        const second=await call('scoring-inspect');expect(second.evaluations).toHaveLength(20);
        expect(second.evaluations.every(row=>Number(row.copies)===1)).toBe(true);
        await call('scoring-wake');
        await race(tenants,[{command:'scoring-tick'},{command:'scoring-archive',modelId:alpha.modelId}]);
        const archived=await call('scoring-inspect');expect(archived.models[0].status).toBe('archived');
        expect(Number(archived.models[0].automatic_enabled)).toBe(0);
        expect(archived.evaluations.length).toBeGreaterThanOrEqual(20);expect(archived.evaluations.length).toBeLessThanOrEqual(21);
        expect(archived.evaluations.every(row=>Number(row.copies)===1)).toBe(true);
        expect((await call('scoring-tick')).processed).toBe(0);
        expect((await call('scoring-inspect')).evaluations).toEqual(archived.evaluations);
        const audience=await call('abm-concurrency-setup');
        const refreshes=await race(tenants,[{command:'abm-tick'},{command:'abm-tick'}]);
        expect(refreshes.map(item=>item.processed).sort((a,b)=>a-b)).toEqual([0,1]);
        const projected=await call('abm-inspect');expect(projected.members).toHaveLength(1);
        expect(Number(projected.members[0].copies)).toBe(1);expect(Number(projected.lists[0].account_count)).toBe(1);
        const edits=await race(tenants,[{command:'abm-competing-edit',...audience,label:'first'},{command:'abm-competing-edit',...audience,label:'second'}]);
        expect(edits.filter(item=>item.saved)).toHaveLength(1);expect(edits.filter(item=>item.conflict)).toHaveLength(1);
        expect(Number((await call('abm-inspect')).lists[0].version_number)).toBe(2);
        const quota=await call('scoring-quota-setup');
        const publications=await race(tenants,[{command:'scoring-competing-publish',modelId:quota.first},{command:'scoring-competing-publish',modelId:quota.second}]);
        expect(publications.filter(item=>item.published)).toHaveLength(1);expect(publications.filter(item=>item.quotaRejected)).toHaveLength(1);
        expect((await call('scoring-inspect')).models.filter(model=>model.status==='published')).toHaveLength(quota.limit);
        expect((await call('scoring-inspect',{index:1})).models.filter(model=>model.status==='published')).toHaveLength(1);
    }catch(error){failure=error;}finally{try{await call('cleanup');}catch(error){if(!failure)failure=error;}}
    if(failure)throw failure;
});

test('Phase 7 maximum capacity boundaries',async({},info)=>{
    test.setTimeout(900000);
    test.skip(process.env.NEXA_AUTOMATION_ACCEPTANCE!=='1','Use the explicit local Phase7Workers acceptance runner.');
    expect(['localhost','127.0.0.1']).toContain(new URL(process.env.NEXA_LIVE_URL).hostname);
    const suffix=randomBytes(6).toString('hex');let tenants=['a','b'].map(label=>({id:randomUUID(),slug:`automation-acceptance-${suffix}-${label}`}));let failure;
    const call=(command,options={})=>fixture(command,tenants,options).result;
    try{
        tenants=await call('setup');
        const capacity=await call('phase7-capacity');
        console.log('CAPACITY_RESULT '+JSON.stringify(capacity));
        expect(capacity).toMatchObject({accounts:5000,audienceContacts:5000,accountLists:200,scoringModels:200,historyRows:2001});
        await info.attach('phase7-capacity.json',{body:JSON.stringify(capacity,null,2),contentType:'application/json'});
    }catch(error){failure=error;}finally{try{await call('cleanup');}catch(error){if(!failure)failure=error;}}
    if(failure)throw failure;
});

test('Phase 7 repeated native tenant worker progress',async({},info)=>{
    test.setTimeout(300000);
    test.skip(process.env.NEXA_AUTOMATION_ACCEPTANCE!=='1','Use the explicit local Phase7Workers acceptance runner.');
    expect(['localhost','127.0.0.1']).toContain(new URL(process.env.NEXA_LIVE_URL).hostname);
    const suffix=randomBytes(6).toString('hex');let tenants=['a','b'].map(label=>({id:randomUUID(),slug:`automation-acceptance-${suffix}-${label}`}));let failure;
    const call=(command,options={})=>fixture(command,tenants,options).result;
    try{
        tenants=await call('setup');
        await call('scoring-concurrency-setup',{contacts:500});await call('scoring-concurrency-setup',{contacts:20,index:1});
        const rounds=[];
        for(let round=0;round<20;round++){
            await call('scoring-wake');await call('scoring-wake',{index:1});
            const result=await race(tenants,[{command:'scoring-native-job'},{command:'scoring-native-job',index:1}]);
            expect(result.every(item=>item.status==='Success')).toBe(true);
            expect(result[0].processed).toBe(10);expect(result[1].processed).toBeGreaterThan(0);expect(result[1].processed).toBeLessThanOrEqual(10);
            rounds.push({round:round+1,busy:result[0],small:result[1]});
            if((round+1)%5===0)console.log(`WORKER_PROGRESS ${round+1}/20 rounds: both tenants advanced`);
        }
        const busy=await call('scoring-inspect');expect(busy.evaluations).toHaveLength(200);expect(busy.evaluations.every(row=>Number(row.copies)===1)).toBe(true);
        expect(busy.models[0].scan_cursor).toBeTruthy();
        const small=await call('scoring-inspect',{index:1});expect(small.evaluations).toHaveLength(21);
        expect(small.evaluations.every(row=>Number(row.copies)>=6)).toBe(true);
        await info.attach('phase7-worker-progress.json',{body:JSON.stringify({rounds,scope:'Native JobRunner dispatch with concurrent busy/small tenant jobs; not a global queue selection or production throughput guarantee.'},null,2),contentType:'application/json'});
    }catch(error){failure=error;}finally{try{await call('cleanup');}catch(error){if(!failure)failure=error;}}
    if(failure)throw failure;
});
