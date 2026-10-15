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
test('Nested AND/OR groups persist, explain one award and remain immutable when published',async({page},info)=>{
    await mount(page);await page.getByRole('button',{name:'New model'}).click();await page.getByRole('textbox',{name:'Name',exact:true}).fill('Grouped fit');
    await page.getByRole('button',{name:'Remove rule',exact:true}).click();await page.getByRole('button',{name:'Add condition group',exact:true}).click();
    const group=page.locator('[data-group]');await group.getByRole('spinbutton',{name:'Points',exact:true}).fill('40');
    await group.getByRole('button',{name:'Add subgroup',exact:true}).click();
    const sub=group.locator('[data-condition-group]').nth(1);await sub.getByRole('combobox',{name:'Match',exact:true}).selectOption('or');
    await sub.getByRole('button',{name:'Add condition',exact:true}).click();
    const leaf=sub.locator('[data-condition-leaf]').nth(1);await leaf.getByRole('combobox',{name:'Property',exact:true}).selectOption('marketingStatus');await leaf.getByRole('combobox',{name:'Condition',exact:true}).selectOption('equals');await leaf.getByRole('combobox',{name:'Value',exact:true}).selectOption('Marketing');
    await page.getByRole('button',{name:'Save draft',exact:true}).click();
    const payload=await page.evaluate(()=>requests.at(-1).input.definition.rules[0]);expect(payload.conditions.operator).toBe('and');expect(payload.conditions.conditions[1]).toMatchObject({operator:'or',conditions:[{field:'title',operator:'has_value'},{field:'marketingStatus',operator:'equals',value:'Marketing'}]});
    await expect(page.locator('[data-group] [data-group-operator]').nth(1)).toHaveValue('or');
    await page.evaluate(()=>{view.record={id:'contact',get:()=> 'Morgan'};view.element.querySelector('[data-action="preview"]').disabled=false;Espo.Ajax.postRequest=async()=>({score:40,categoryScores:{fit:40,engagement:0},contributions:[{operator:'and',conditions:[{field:'title',operator:'has_value',actual:'Director',matched:true}],matched:true,points:40}]});});
    await page.getByRole('button',{name:'Preview score',exact:true}).click();await expect(page.locator('[data-result]')).toContainText('All conditions: Matched');await expect(page.locator('[data-result]')).toContainText('+40');
    const results=await new AxeBuilder({page}).include('.nexa-scoring').withTags(['wcag2a','wcag2aa','wcag21aa']).analyze();expect(results.violations).toEqual([]);expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBe(true);
    await info.attach('advanced-groups.png',{body:await page.screenshot({path:path.join(root,`test-results/scoring-groups-${info.project.name}.png`)}),contentType:'image/png'});
    await page.evaluate(()=>{catalog.models[0].status='published';view.workspace=structuredClone(catalog);view.edit(view.workspace.models[0]);});await expect(page.getByRole('button',{name:'Add condition group',exact:true})).toBeHidden();await expect(page.locator('[data-group] [data-group-operator]').first()).toBeDisabled();
});

test('Account monetary rules retain explicit enabled currency selections',async({page},info)=>{
    await mount(page);await page.evaluate(()=>{catalog.fields.Account.annualRevenue={type:'currency',label:'Annual revenue',currencies:['USD','GBP'],defaultCurrency:'USD'};view.workspace=structuredClone(catalog);});
    await page.getByRole('button',{name:'New model'}).click();await page.getByRole('textbox',{name:'Name',exact:true}).fill('Company revenue');await page.getByRole('combobox',{name:'Records',exact:true}).selectOption('Account');
    await page.getByRole('combobox',{name:'Property',exact:true}).selectOption('annualRevenue');await page.getByRole('combobox',{name:'Condition',exact:true}).selectOption('greater_than');await page.getByRole('textbox',{name:'Value',exact:true}).fill('100.01');await page.getByRole('combobox',{name:'Comparison currency',exact:true}).selectOption('GBP');
    await page.getByRole('button',{name:'Save draft',exact:true}).click();expect(await page.evaluate(()=>requests.at(-1).input.definition.rules[0])).toMatchObject({field:'annualRevenue',value:'100.01',currency:'GBP'});await expect(page.getByRole('combobox',{name:'Comparison currency',exact:true})).toHaveValue('GBP');
    await page.evaluate(()=>{view.record={id:'account',get:()=> 'Company'};view.element.querySelector('[data-action="preview"]').disabled=false;Espo.Ajax.postRequest=async()=>({score:10,contributions:[{field:'annualRevenue',operator:'greater_than',actual:'125.00',expected:'100.01',currency:'GBP',matched:true,points:10}],inputs:{annualRevenue:{amount:'250',code:'USD',normalized:{GBP:'125.00'},rateMode:'manual',effectiveDate:null}}});});
    await page.getByRole('button',{name:'Preview score',exact:true}).click();await expect(page.locator('[data-result]')).toContainText('125.00 GBP');await page.getByText('Currency evidence',{exact:true}).click();await expect(page.locator('[data-result]')).toContainText('250 USD');
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBe(true);await info.attach('currency.png',{body:await page.screenshot({path:path.join(root,`test-results/scoring-currency-${info.project.name}.png`)}),contentType:'image/png'});
});

test('Fit and engagement categories persist and render score breakdowns',async({page})=>{
    await mount(page);await page.getByRole('button',{name:'Director fit',exact:true}).click();
    await page.getByRole('combobox',{name:'Score category',exact:true}).selectOption('engagement');
    await page.getByRole('button',{name:'Save draft'}).click();
    expect(await page.evaluate(()=>requests.at(-1).input.definition.rules[0].category)).toBe('engagement');
    await expect(page.getByRole('combobox',{name:'Score category',exact:true})).toHaveValue('engagement');
    await page.evaluate(()=>{view.record={id:'contact',get:()=> 'Morgan'};view.element.querySelector('[data-action="preview"]').disabled=false;Espo.Ajax.postRequest=async()=>({score:25,categoryScores:{fit:0,engagement:25},contributions:[]});});
    await page.getByRole('button',{name:'Preview score'}).click();
    await expect(page.locator('.ns-category-summary')).toContainText('Fit 0');await expect(page.locator('.ns-category-summary')).toContainText('Engagement 25');
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBe(true);
});

test('Scoring catalogue and editor retain accessible labels and safe content',async({page})=>{
    await mount(page);await page.getByRole('button',{name:'Director fit',exact:true}).click();
    const results=await new AxeBuilder({page}).include('.nexa-scoring').withTags(['wcag2a','wcag2aa','wcag21aa']).analyze();expect(results.violations).toEqual([]);
    await page.evaluate(()=>{catalog.models[0].name='<img src=x onerror="window.attacked=1">';view.workspace=catalog;view.renderModels();});
    expect(await page.locator('[data-models] img').count()).toBe(0);expect(await page.evaluate(()=>!!window.attacked)).toBe(false);
});
test('Tenant custom properties use readable labels and configured choices',async({page})=>{
    await mount(page);
    await page.evaluate(()=>{
        catalog.fields.Contact['custom:membership']={type:'enum',label:'Membership (custom)',options:['Gold','Silver']};
        catalog.fields.Contact['custom:verified']={type:'enum',label:'Verified (custom)',options:['true','false']};
        view.workspace=structuredClone(catalog);
    });
    await page.getByRole('button',{name:'New model'}).click();
    await page.getByRole('textbox',{name:'Name',exact:true}).fill('Membership fit');
    await page.getByRole('combobox',{name:'Property'}).selectOption('custom:membership');
    await page.getByRole('combobox',{name:'Condition'}).selectOption('equals');
    await page.getByRole('combobox',{name:'Value',exact:true}).selectOption('Gold');
    await page.getByRole('button',{name:'Save draft'}).click();
    expect(await page.evaluate(()=>requests.at(-1).input.definition.rules[0])).toMatchObject({field:'custom:membership',value:'Gold'});
    await expect(page.getByRole('combobox',{name:'Property'})).toHaveValue('custom:membership');
    await page.getByRole('combobox',{name:'Property'}).selectOption('custom:verified');
    await page.getByRole('combobox',{name:'Condition'}).selectOption('equals');
    await page.getByRole('combobox',{name:'Value',exact:true}).selectOption('false');
    await page.getByRole('button',{name:'Save draft'}).click();
    expect(await page.evaluate(()=>requests.at(-1).input.definition.rules[0])).toMatchObject({field:'custom:verified',value:'false'});
});
test('Behavior decay authoring persists explicit intervals without affecting fit rules',async({page},info)=>{
    await mount(page);
    await page.evaluate(()=>{catalog.fields.Contact.behavior_email_clicked_30.event='email.clicked';view.workspace=structuredClone(catalog);});
    await page.getByRole('button',{name:'New model'}).click();await page.getByRole('textbox',{name:'Name',exact:true}).fill('Engagement decay');
    await page.getByRole('combobox',{name:'Property'}).selectOption('behavior_email_clicked_30');
    await page.getByRole('checkbox',{name:'Reduce behaviour points'}).check();
    await page.getByRole('spinbutton',{name:'Start after days'}).fill('7');await page.getByRole('spinbutton',{name:'Repeat every days'}).fill('3');await page.getByRole('spinbutton',{name:'Points to remove'}).fill('2');
    await page.getByRole('button',{name:'Save draft'}).click();
    expect(await page.evaluate(()=>requests.at(-1).input.definition.rules[0].decay)).toEqual({afterDays:7,everyDays:3,points:2});
    await expect(page.getByRole('checkbox',{name:'Reduce behaviour points'})).toBeChecked();
    const results=await new AxeBuilder({page}).include('.nexa-scoring').withTags(['wcag2a','wcag2aa','wcag21aa']).analyze();expect(results.violations).toEqual([]);
    await info.attach('decay.png',{body:await page.screenshot({path:path.join(root,`test-results/scoring-decay-${info.project.name}.png`)}),contentType:'image/png'});
    await page.getByRole('combobox',{name:'Property'}).selectOption('title');await expect(page.getByRole('checkbox',{name:'Reduce behaviour points'})).toBeHidden();
    await page.getByRole('button',{name:'Save draft'}).click();expect(await page.evaluate(()=>requests.at(-1).input.definition.rules[0].decay)).toBeUndefined();
});
test('Published models expose administrator-controlled automatic recalculation',async({page})=>{
    await mount(page);
    await page.evaluate(()=>{
        catalog.models[0].status='published';view.workspace=structuredClone(catalog);view.edit(view.workspace.models[0]);
        const post=Espo.Ajax.postRequest;
        Espo.Ajax.postRequest=async(url,input)=>{if(input.action!=='schedule')return post(url,input);requests.push({url,input});catalog.models[0].automaticEnabled=input.enabled?1:0;catalog.models[0].intervalHours=input.intervalHours;return{id:'model'};};
    });
    await page.getByRole('checkbox',{name:'Enabled',exact:true}).check();await page.getByRole('combobox',{name:'Interval',exact:true}).selectOption('6');await page.getByRole('button',{name:'Update schedule'}).click();
    expect(await page.evaluate(()=>requests.at(-1).input)).toEqual({action:'schedule',enabled:true,intervalHours:6});await expect(page.getByRole('checkbox',{name:'Enabled',exact:true})).toBeChecked();
    await page.getByRole('checkbox',{name:'Enabled',exact:true}).uncheck();await page.getByRole('button',{name:'Update schedule'}).click();await expect(page.getByRole('checkbox',{name:'Enabled',exact:true})).not.toBeChecked();
    await page.evaluate(()=>{catalog.canManage=false;view.workspace=structuredClone(catalog);view.edit(view.workspace.models[0]);});await expect(page.getByRole('button',{name:'Update schedule'})).toBeHidden();
});
test('Lifecycle policies and reasoned adjustments use the existing scoring workspace',async({page},info)=>{
    await mount(page);
    await page.evaluate(()=>{catalog.fields.Contact.lifecycleStage={type:'enum',options:['Lead','MarketingQualifiedLead','SalesQualifiedLead']};view.workspace=structuredClone(catalog);});
    await page.getByRole('button',{name:'Director fit',exact:true}).click();
    await page.getByRole('checkbox',{name:'Promote qualified records'}).check();
    await page.getByRole('button',{name:'Save draft'}).click();
    expect(await page.evaluate(()=>requests.at(-1).input.definition.lifecycle.thresholds)).toEqual([{min:0,stage:'Lead'},{min:51,stage:'MarketingQualifiedLead'},{min:81,stage:'SalesQualifiedLead'}]);
    await page.evaluate(async()=>{
        catalog.models[0].status='published';catalog.models[0].isPrimary=1;view.workspace=structuredClone(catalog);view.edit(view.workspace.models[0]);view.record={id:'contact',get:()=> 'Morgan'};
        const get=Espo.Ajax.getRequest;Espo.Ajax.getRequest=async url=>url.endsWith('/workspace')?get(url):{evaluations:[],adjustments:[{id:'adjustment',delta:12,reason:'Verified qualification',status:'active',createdAt:'2026-10-14 10:00:00'}]};
        Espo.Ajax.postRequest=async(url,input)=>{requests.push({url,input});return{result:{score:37,modelScore:25,adjustmentTotal:12,contributions:[]}};};await view.history();
    });
    await page.locator('[data-adjustment-form] [name="delta"]').fill('12');await page.getByRole('textbox',{name:'Reason',exact:true}).fill('Verified qualification');
    await page.getByRole('button',{name:'Apply adjustment'}).click();await expect(page.locator('[data-result]')).toContainText('37 points');
    expect(await page.evaluate(()=>requests.at(-1).input)).toMatchObject({recordId:'contact',delta:12,reason:'Verified qualification',expiresAt:null});
    await page.getByRole('button',{name:'Revoke adjustment',exact:true}).click();await page.getByRole('textbox',{name:'Reason',exact:true}).fill('Evidence corrected');await page.getByRole('button',{name:'Apply adjustment'}).click();
    expect(await page.evaluate(()=>requests.at(-1).input)).toMatchObject({recordId:'contact',revokeId:'adjustment',reason:'Evidence corrected'});
    const results=await new AxeBuilder({page}).include('.nexa-scoring').withTags(['wcag2a','wcag2aa','wcag21aa']).analyze();expect(results.violations).toEqual([]);
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBe(true);
    await info.attach('adjustments.png',{body:await page.screenshot(),contentType:'image/png'});
});

test('Published Contact models allow explicit primary score ownership and release',async({page})=>{
    await mount(page);
    await page.evaluate(()=>{
        catalog.models[0].status='published';view.workspace=structuredClone(catalog);view.edit(view.workspace.models[0]);
        const post=Espo.Ajax.postRequest;
        Espo.Ajax.postRequest=async(url,input)=>{if(input.action!=='primary')return post(url,input);requests.push({url,input});catalog.models[0].isPrimary=input.enabled?1:0;return{id:'model'};};
    });
    await page.getByRole('checkbox',{name:'Use this model',exact:true}).check();await page.getByRole('button',{name:'Update ownership'}).click();
    expect(await page.evaluate(()=>requests.at(-1).input)).toEqual({action:'primary',enabled:true});await expect(page.getByRole('checkbox',{name:'Use this model',exact:true})).toBeChecked();
    await page.getByRole('checkbox',{name:'Use this model',exact:true}).uncheck();await page.getByRole('button',{name:'Update ownership'}).click();await expect(page.getByRole('checkbox',{name:'Use this model',exact:true})).not.toBeChecked();
    await page.evaluate(()=>{catalog.canManage=false;view.workspace=structuredClone(catalog);view.edit(view.workspace.models[0]);});await expect(page.getByRole('button',{name:'Update ownership'})).toBeHidden();
});
