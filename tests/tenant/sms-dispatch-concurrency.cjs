const {spawn}=require('node:child_process');
const {randomUUID,randomBytes}=require('node:crypto');
const path=require('node:path');
const assert=require('node:assert/strict');
const root=path.resolve(__dirname,'../..');
const env={...process.env,NEXA_AUTOMATION_ACCEPTANCE:'1',NEXA_SECRET_ACTIVE_KEY_ID:'sms-concurrency',NEXA_SECRET_KEYS:JSON.stringify({'sms-concurrency':randomBytes(32).toString('base64')})};
function start(command,tenants,extra={}){
    let release,readyResolve;const ready=new Promise(resolve=>readyResolve=resolve);
    const child=spawn(process.env.NEXA_PHP_PATH||'php',[path.join(__dirname,'AutomationAcceptanceFixture.php')],{cwd:root,env,windowsHide:true,stdio:['pipe','pipe','pipe']});
    let out='',err='';const timer=setTimeout(()=>child.kill(),60000);
    const done=new Promise((resolve,reject)=>{
        child.stdout.on('data',chunk=>{out+=chunk;if(out.startsWith('READY\n'))readyResolve();});child.stderr.on('data',chunk=>err+=chunk);
        child.on('error',reject);child.on('close',code=>{clearTimeout(timer);if(code)return reject(new Error(err||'SMS acceptance process failed.'));try{resolve(JSON.parse(out.replace(/^READY\r?\n/,'')));}catch{reject(new Error('Invalid SMS fixture output.'));}});
    });
    child.stdin.write(JSON.stringify({command,tenants,...extra})+'\n');release=()=>child.stdin.end('GO\n');if(!extra.barrier)child.stdin.end();
    done.catch(()=>{});
    return {ready:extra.barrier?Promise.race([ready,done.then(()=>{throw new Error('SMS worker exited before its readiness barrier.');})]):ready,done,release};
}
(async()=>{
    const suffix=randomBytes(6).toString('hex');let tenants=['a','b'].map(label=>({id:randomUUID(),slug:`automation-acceptance-${suffix}-${label}`}));let configured=false;
    try{
        tenants=await start('setup',tenants).done;configured=true;
        await start('sms-dispatch-seed',tenants,{index:0,count:4}).done;await start('sms-dispatch-seed',tenants,{index:1,count:1}).done;
        const one=start('sms-dispatch-run',tenants,{index:0,barrier:true}),two=start('sms-dispatch-run',tenants,{index:0,barrier:true});
        await Promise.all([one.ready,two.ready]);one.release();two.release();await Promise.all([one.done,two.done]);
        const alpha=await start('sms-dispatch-inspect',tenants,{index:0}).done;
        assert.equal(alpha.posts.length,2);assert(alpha.posts.every(row=>Number(row.count)===1));
        assert.equal(alpha.rows.filter(row=>row.status==='submitted').length,2);assert.equal(alpha.rows.filter(row=>row.status==='queued').length,2);
        assert.equal(alpha.rows.reduce((sum,row)=>sum+Number(row.attempts),0),2);
        await start('sms-dispatch-run',tenants,{index:1}).done;const beta=await start('sms-dispatch-inspect',tenants,{index:1}).done;
        assert.equal(beta.posts.length,1);assert.equal(beta.rows[0].status,'submitted');
        process.stdout.write('SMS simultaneous-worker/quota/fairness acceptance passed (two independent processes; fake transport).\n');
    }finally{if(configured)await start('cleanup',tenants).done;}
})().catch(error=>{process.stderr.write(error.stack+'\n');process.exitCode=1;});
