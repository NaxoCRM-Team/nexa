const {test,expect}=require('@playwright/test');
const fs=require('node:fs');
const path=require('node:path');
const root=path.resolve(__dirname,'../..');

test('Received SMS uses the existing safe Contact timeline and isolated card layout',async({page})=>{
    await page.setContent('<main data-nexa-contact-workspace></main><div class="nexa-activity-workspace-page"><article class="nexa-activity-card" id="agenda-card"></article></div>');
    for(const file of ['crm-workflows.css','activity-workspace.css'])await page.addStyleTag({content:fs.readFileSync(path.join(root,'espocrm/client/custom/css',file),'utf8')});
    await page.evaluate(()=>{
        window.define=(name,deps,factory)=>{window.ContactView=factory(class{},null,null,null,null,{hydrate(){}},{},class{});};
        window.Espo={Ajax:{getRequest:async()=>({timeline:[],messaging:[
            {id:'message',body:'Hello <img src=x onerror="window.smsXss=true">',createdAt:'2026-10-18 09:00:00',connectionName:'Support SMS',mediaCount:1},
            {id:'unavailable',body:null,createdAt:'2026-10-18 08:00:00',connectionName:'Support SMS',mediaCount:0},
        ]})}};
    });
    await page.addScriptTag({content:fs.readFileSync(path.join(root,'espocrm/client/custom/src/views/contact/record/detail-workspace.js'),'utf8')});
    const result=await page.evaluate(async()=>{
        const view=new ContactView();view.model={id:'contact'};view.element=document.querySelector('main');
        view.contactNoteDate=value=>new Date(value.replace(' ','T')+'Z');view.renderContactActivities=()=>{};
        view.collapsedContactActivityIds=new Set();
        await view.loadContactBehaviorTimeline();
        view.element.innerHTML=view.contactBehaviorActivities.map(activity=>view.contactActivityCard(activity)).join('');
        return view.contactBehaviorActivities;
    });
    expect(result.map(item=>item.type)).toEqual(['sms','sms']);
    await expect(page.locator('[data-nexa-activity-id="sms-message"] .nexa-activity-details')).toContainText('Hello <img src=x onerror="window.smsXss=true">');
    await expect(page.locator('[data-nexa-activity-id="sms-unavailable"]')).toContainText('Message content unavailable');
    expect(await page.evaluate(()=>window.smsXss)).toBeUndefined();
    await expect(page.locator('main img,main [data-nexa-activity-actions],main [data-nexa-activity-comment-form]')).toHaveCount(0);
    await expect(page.locator('main .fa-sms')).toHaveCount(2);
    await expect(page.locator('main .nexa-activity-card').first()).toHaveCSS('display','block');
    await expect(page.locator('#agenda-card')).toHaveCSS('display','flex');
    expect(await page.locator('main').evaluate(node=>node.scrollWidth<=node.clientWidth)).toBe(true);
});
