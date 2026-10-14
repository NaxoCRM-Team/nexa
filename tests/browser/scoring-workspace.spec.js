const {test,expect}=require('@playwright/test');
const AxeBuilder=require('@axe-core/playwright').default;
const fs=require('node:fs');const path=require('node:path');const root=path.resolve(__dirname,'../..');
async function mount(page){
    const tpl=fs.readFileSync(path.join(root,'espocrm/client/custom/res/templates/scoring/workspace.tpl'),'utf8');
    const css=fs.readFileSync(path.join(root,'espocrm/client/custom/css/scoring-workspace.css'),'utf8');
    await page.setContent(`<meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0;font:14px Arial}*{box-sizing:border-box}[hidden]{display:none!important}.btn{padding:8px;border:1px solid #bacbc4;background:#fff}.form-control{height:36px;border:1px solid #bacbc4;padding:6px}${css}</style>${tpl}`);
    await page.evaluate(()=>{window.define=(name,deps,factory)=>{if(Array.isArray(name))window.Tables=deps();else window.ScoringView=factory(class{setPageTitle(){}getStorage(){return{get:()=>({}),set(){}};}},window.Tables);};});
    await page.addScriptTag({content:fs.readFileSync(path.join(root,'espocrm/client/custom/src/workspace-table.js'),'utf8')});
    await page.addScriptTag({content:fs.readFileSync(path.join(root,'espocrm/client/custom/src/views/scoring/workspace.js'),'utf8')});
    await page.evaluate(async()=>{
        window.requests=[];
        window.catalog={canManage:true,publishedLimit:5,fields:{Contact:{title:{type:'varchar'},marketingStatus:{type:'enum',options:['Marketing','Non-Marketing']},behavior_email_clicked_30:{type:'int',label:'Email clicks (last 30 days)'}},Account:{industry:{type:'enum',options:['Technology','Finance']}},Opportunity:{probability:{type:'int'}}},models:[{id:'model',name:'Director fit',entityType:'Contact',status:'draft',version:1,definition:{rules:[{id:'title',field:'title',operator:'equals',value:'Director',points:25}]}}]};
        window.Espo={Ajax:{getRequest:async url=>url.endsWith('/workspace')?structuredClone(catalog):{evaluations:[]},postRequest:async(url,input)=>{requests.push({url,input});if(window.reject)throw new Error('The rule needs attention.');if(url.endsWith('/models')){catalog.models=[{id:'saved',name:input.name,entityType:input.entityType,status:'draft',version:1,definition:input.definition}];return{id:'saved'};}const points=catalog.models[0].definition.rules[0].points;return{score:Math.max(0,points),contributions:[{field:'title',actual:'Director',matched:true,points}]};}}};
        const view=window.view=new ScoringView();view.element=document.querySelector('main');view.setup();
        for(const[binding,method]of Object.entries(view.events)){const split=binding.indexOf(' '),event=binding.slice(0,split),selector=binding.slice(split+1);view.element.addEventListener(event,original=>{const target=original.target.closest(selector);if(target)view[method]({currentTarget:target,preventDefault:()=>original.preventDefault()});});}
        await view.load();
    });
}
test('Scoring models author positive/negative rules with live search and native record previews',async({page},info)=>{
    await mount(page);await page.getByRole('searchbox',{name:'Search scoring models'}).fill('missing');await expect(page.getByText('Total 0')).toBeVisible();
    await page.getByRole('searchbox',{name:'Search scoring models'}).fill('');await page.getByRole('button',{name:'New model'}).click();
    await expect(page.getByRole('textbox',{name:'Name',exact:true})).toBeFocused();await page.getByRole('textbox',{name:'Name',exact:true}).fill('Customer fit');
    await page.getByRole('combobox',{name:'Property'}).selectOption('title');await page.getByRole('combobox',{name:'Condition'}).selectOption('equals');await page.getByRole('textbox',{name:'Value',exact:true}).fill('Director');await page.getByRole('spinbutton',{name:'Points'}).fill('-10');
    await page.getByText('Score bands',{exact:true}).click();await page.locator('[data-band-label]').nth(1).fill('Warm');
    await page.evaluate(()=>{reject=true;});await page.getByRole('button',{name:'Save draft'}).click();await expect(page.getByRole('alert')).toHaveText('The rule needs attention.');
    await expect(page.getByRole('button',{name:'Save draft'})).toBeEnabled();await page.evaluate(()=>{reject=false;});await page.getByRole('button',{name:'Save draft'}).click();
    expect(await page.evaluate(()=>requests.at(-1).input.definition.rules[0])).toMatchObject({field:'title',operator:'equals',value:'Director',points:-10});
    expect(await page.evaluate(()=>requests.at(-1).input.definition.thresholds[1])).toEqual({min:21,label:'Warm'});
    await expect(page.getByRole('heading',{name:'Customer fit'})).toBeVisible();
    await page.evaluate(()=>{view.record={id:'contact',get:()=> 'Morgan'};view.element.querySelector('[data-action="preview"]').disabled=false;});
    await page.getByRole('button',{name:'Preview score'}).click();await expect(page.locator('[data-result]')).toContainText('0 points');await expect(page.locator('[data-result]')).toContainText('Director');await expect(page.locator('[data-result] .ns-negative')).toHaveText('-10');
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBe(true);
    await info.attach('scoring.png',{body:await page.screenshot({path:path.join(root,`test-results/scoring-${info.project.name}.png`)}),contentType:'image/png'});
});
test('Scoring catalogue and editor retain accessible labels and safe content',async({page})=>{
    await mount(page);await page.getByRole('button',{name:'Director fit',exact:true}).click();
    const results=await new AxeBuilder({page}).include('.nexa-scoring').withTags(['wcag2a','wcag2aa','wcag21aa']).analyze();expect(results.violations).toEqual([]);
    await page.evaluate(()=>{catalog.models[0].name='<img src=x onerror="window.attacked=1">';view.workspace=catalog;view.renderModels();});
    expect(await page.locator('[data-models] img').count()).toBe(0);expect(await page.evaluate(()=>!!window.attacked)).toBe(false);
});
