const {test,expect}=require('@playwright/test');
const AxeBuilder=require('@axe-core/playwright').default;
const fs=require('node:fs'),path=require('node:path');
const root=path.resolve(__dirname,'../..');
async function mount(page){
    const read=file=>fs.readFileSync(path.join(root,file),'utf8');
    await page.setContent(`<meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0;font:14px Arial}*{box-sizing:border-box}.btn{padding:7px;border:1px solid #bacbc4;background:white;color:#20352f;text-decoration:none}.form-control{height:34px;padding:6px;border:1px solid #bacbc4}${read('espocrm/client/custom/css/personalization-history.css')}</style>${read('espocrm/client/custom/res/templates/marketing-email/personalization-history.tpl')}`);
    await page.evaluate(()=>{window.Dep=class{setPageTitle(){}afterRender(){}once(){}getStorage(){return{get:()=>({}),set(){}};}};window.define=(name,deps,factory)=>{if(Array.isArray(name))window.Tables=deps();else window.HistoryView=factory(Dep,Tables);};});
    for(const file of ['espocrm/client/custom/src/workspace-table.js','espocrm/client/custom/src/views/marketing-email/personalization-history.js'])await page.addScriptTag({content:read(file)});
    await page.evaluate(()=>{
        window.requests=[];window.items=[
            {id:'decision-a',occurredAt:'2026-10-14 12:00:00',clock:'UTC',source:'Email content test',recordType:'Contact',recordId:'contact-a',recordName:'Morgan Lee',actor:'Taylor Smith',outcome:'Matching content',verification:'Stored condition outcomes',details:[{label:'Lead status',operator:'equals',expected:'New',matched:true}]},
            {id:'decision-b',occurredAt:'2026-10-14 11:00:00',clock:'Legacy server time',source:'Published landing page',recordType:'NexaLandingPages',recordId:null,recordName:'Demo page',actor:'Anonymous visitor',outcome:'1 variant(s) selected',verification:'Published version 2',details:[{label:'Campaign source',operator:'equals',expected:'newsletter',matched:true,heading:'<script>unsafe()</script>'}]},
            {id:'decision-c',occurredAt:'2026-10-14 10:00:00',clock:'UTC',source:'Email content test',recordType:'Contact',recordId:'contact-c',recordName:'Robin Dale',actor:'System',outcome:'Evidence unavailable',verification:'Restricted or invalid evidence',details:[]}
        ];
        window.Espo={Ajax:{getRequest:async(url,input)=>{
            requests.push({url,input});if(window.failHistory)throw new Error('History unavailable.');
            let rows=items.filter(row=>(input.source==='all'||(input.source==='email'?row.recordType==='Contact':row.recordType==='NexaLandingPages'))&&(!input.search||row.id.includes(input.search)||String(row.recordId).includes(input.search)));
            const response={list:input.cursor?rows.slice(2):rows.slice(0,2),cursor:!input.cursor&&rows.length>2?'more':null,scannedCount:Math.min(rows.length,2)};
            if(window.holdNext){window.holdNext=false;return new Promise(resolve=>window.releaseHistory=()=>resolve(response));}
            return response;
        }}};
        const view=window.view=new HistoryView();view.element=document.querySelector('section');view.setup();
        for(const[binding,method]of Object.entries(view.events)){const split=binding.indexOf(' '),event=binding.slice(0,split),selector=binding.slice(split+1);view.element.addEventListener(event,original=>{const target=original.target.closest(selector);if(target)view[method]({currentTarget:target});});}
        view.afterRender();
    });
    await expect(page.locator('[data-count]')).toHaveText('Loaded 2 decisions');
}
test('Decision history explains outcomes and supports filters, scrolling and safe content',async({page},info)=>{
    await mount(page);
    await page.getByText('View decision',{exact:true}).first().click();await expect(page.locator('details').first()).toContainText('Lead status: Matched');
    await page.locator('[data-scroll]').evaluate(node=>{node.scrollTop=node.scrollHeight;node.dispatchEvent(new Event('scroll'));});await expect(page.locator('[data-count]')).toHaveText('Loaded 3 decisions');
    await expect(page.getByRole('button',{name:'Load more decisions',exact:true})).toBeHidden();
    await page.getByRole('combobox',{name:'Source',exact:true}).selectOption('landing');await expect(page.locator('[data-count]')).toHaveText('Loaded 1 decision');
    await page.getByText('View decision',{exact:true}).click();await expect(page.locator('details')).toContainText('<script>unsafe()</script>');await expect(page.locator('.np-table script')).toHaveCount(0);
    await page.getByRole('searchbox',{name:'Search decisions'}).fill('missing');await expect(page.getByText('No decisions found',{exact:true})).toBeVisible();
    await expect.poll(()=>page.evaluate(()=>requests.at(-1).input.search)).toBe('missing');
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBe(true);
    const results=await new AxeBuilder({page}).include('.nexa-personalization-history').withTags(['wcag2a','wcag2aa']).analyze();expect(results.violations).toEqual([]);
    await page.screenshot({path:info.outputPath('personalization-history.png'),fullPage:true});
});
test('History rejects stale responses and can recover from request failures',async({page})=>{
    await mount(page);await page.evaluate(()=>window.holdNext=true);await page.getByRole('button',{name:'Refresh decisions'}).click();
    await expect.poll(()=>page.evaluate(()=>typeof window.releaseHistory)).toBe('function');
    await page.getByRole('combobox',{name:'Source',exact:true}).selectOption('landing');await expect(page.locator('[data-count]')).toHaveText('Loaded 1 decision');
    await page.evaluate(()=>releaseHistory());await expect(page.locator('[data-count]')).toHaveText('Loaded 1 decision');
    await page.evaluate(()=>window.failHistory=true);await page.getByRole('button',{name:'Refresh decisions'}).click();await expect(page.getByRole('alert')).toHaveText('History unavailable.');
    await page.evaluate(()=>window.failHistory=false);await page.getByRole('button',{name:'Refresh decisions'}).click();await expect(page.getByRole('alert')).toBeHidden();await expect(page.locator('[data-count]')).toHaveText('Loaded 1 decision');
});
