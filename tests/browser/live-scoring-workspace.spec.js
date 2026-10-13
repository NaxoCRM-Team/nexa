const {test,expect}=require('@playwright/test');
const {spawn}=require('node:child_process');const {randomUUID,randomBytes}=require('node:crypto');const path=require('node:path');
const root=path.resolve(__dirname,'../..');const base=(process.env.NEXA_LIVE_URL||'').replace(/\/$/,'');
test.use({trace:'off',screenshot:'off'});
function fixture(command,tenants){return new Promise((resolve,reject)=>{const p=spawn(process.env.NEXA_PHP_PATH||'php',[path.join(root,'tests/tenant/AutomationAcceptanceFixture.php')],{cwd:root,env:process.env,windowsHide:true,stdio:['pipe','pipe','pipe']});let out='',err='';const timeout=setTimeout(()=>{p.kill();reject(new Error('Scoring fixture timed out.'));},45000);p.stdout.on('data',d=>{out+=d;});p.stderr.on('data',d=>{err+=d;});p.on('error',e=>{clearTimeout(timeout);reject(e);});p.on('close',code=>{clearTimeout(timeout);if(code!==0)return reject(new Error(`Scoring fixture failed: ${err}`));try{resolve(JSON.parse(out));}catch{reject(new Error('Invalid fixture response.'));}});p.stdin.end(JSON.stringify({command,tenants})+'\n');});}
test('Authenticated scoring authoring, native record selection and recorded evaluation work end to end',async({page})=>{
    test.setTimeout(120000);test.skip(process.env.NEXA_AUTOMATION_ACCEPTANCE!=='1'||!base,'Run the explicit local -Suite Scoring acceptance.');
    expect(['localhost','127.0.0.1']).toContain(new URL(base).hostname);
    const suffix=randomBytes(6).toString('hex');let tenants=['a','b'].map(label=>({id:randomUUID(),slug:`automation-acceptance-${suffix}-${label}`}));let failure;
    try{
        tenants=await fixture('setup',tenants);
        await page.goto(`${base}/login/`);await page.locator('#field-userName').fill(tenants[0].username);await page.locator('#field-password').fill(tenants[0].password);await page.locator('#login-form button[type="submit"]').click();await page.waitForURL(new RegExp(`/w/${tenants[0].slug}(?:/.*)?$`));
        await page.goto(`${base}/w/${tenants[0].slug}/NexaScoring`);await expect(page.getByRole('heading',{name:'Scoring & Lifecycle',exact:true})).toBeVisible();await expect(page.locator('.nexa-scoring [data-loading]')).toBeHidden();
        const contact=await page.evaluate(async id=>{await Espo.Ajax.putRequest(`Contact/${id}`,{title:'Director'});return Espo.Ajax.getRequest(`Contact/${id}`);},tenants[0].contactId);
        await page.getByRole('button',{name:'New model',exact:true}).click();await page.getByRole('textbox',{name:'Name',exact:true}).fill('Live director fit');await page.getByRole('combobox',{name:'Property',exact:true}).selectOption('title');await page.getByRole('combobox',{name:'Condition',exact:true}).selectOption('equals');await page.getByRole('textbox',{name:'Value',exact:true}).fill('Director');await page.getByRole('spinbutton',{name:'Points',exact:true}).fill('25');await page.getByRole('button',{name:'Save draft',exact:true}).click();
        await expect(page.getByRole('heading',{name:'Live director fit',exact:true})).toBeVisible();await page.getByRole('button',{name:'Choose record',exact:true}).click();const dialog=page.getByRole('dialog');await expect(dialog).toBeVisible();await dialog.getByText(contact.name,{exact:true}).first().click();await expect(page.locator('[data-record-name]')).toHaveText(contact.name);await page.getByRole('button',{name:'Preview score',exact:true}).click();await expect(page.locator('[data-result]')).toContainText('25 points');
        await page.getByRole('button',{name:'Publish',exact:true}).click();await expect(page.locator('[data-models]')).toContainText('Published');
        await page.getByRole('button',{name:'Choose record',exact:true}).click();await page.getByRole('dialog').getByText(contact.name,{exact:true}).first().click();await page.getByRole('button',{name:'Record evaluation',exact:true}).click();await expect(page.locator('[data-history]')).toContainText('25 points');
        const workspace=await page.evaluate(()=>Espo.Ajax.getRequest('Nexa/scoring/workspace'));const model=workspace.models.find(m=>m.name==='Live director fit');
        const foreign=await page.evaluate(async({id,recordId})=>{try{await Espo.Ajax.postRequest(`Nexa/scoring/${id}/preview`,{recordId});return true;}catch{return false;}},{id:model.id,recordId:tenants[1].contactId});expect(foreign).toBe(false);
        expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBe(true);
    }catch(error){failure=error;}
    finally{try{await fixture('cleanup',tenants);}catch(error){if(!failure)failure=error;}}
    if(failure)throw failure;
});
