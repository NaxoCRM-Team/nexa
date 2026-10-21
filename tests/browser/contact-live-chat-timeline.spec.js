const {test,expect}=require('@playwright/test');
const fs=require('node:fs');
const path=require('node:path');
const root=path.resolve(__dirname,'../..');

test('Verified live chat distinguishes visitors, staff and bots in safe read-only cards',async({page})=>{
    await page.setContent('<main data-nexa-contact-workspace></main>');
    for(const name of ['crm-workflows.css','activity-workspace.css'])await page.addStyleTag({content:fs.readFileSync(path.join(root,'espocrm/client/custom/css',name),'utf8')});
    await page.evaluate(()=>{
        window.define=(name,deps,factory)=>{window.ContactView=factory(class{},null,null,null,null,{hydrate(){}},{},class{});};
        window.Espo={Ajax:{getRequest:async()=>({timeline:[],liveChat:[
            {id:'1',author:'visitor',body:'Question <img src=x onerror="window.chatXss=true">',createdAt:'2026-10-18 10:00:00'},
            {id:'2',author:'staff',authorName:'Alex',body:'Staff reply',createdAt:'2026-10-18 10:01:00'},
            {id:'3',author:'bot',body:'Automated greeting',createdAt:'2026-10-18 09:59:00'},
        ]})}};
    });
    await page.addScriptTag({content:fs.readFileSync(path.join(root,'espocrm/client/custom/src/views/contact/record/detail-workspace.js'),'utf8')});
    const activities=await page.evaluate(async()=>{
        const view=new ContactView();view.model={id:'contact'};view.element=document.querySelector('main');
        view.contactNoteDate=value=>new Date(value.replace(' ','T')+'Z');view.renderContactActivities=()=>{};view.collapsedContactActivityIds=new Set();
        await view.loadContactBehaviorTimeline();view.element.innerHTML=view.contactBehaviorActivities.map(item=>view.contactActivityCard(item)).join('');
        return view.contactBehaviorActivities;
    });
    expect(activities.map(item=>item.type)).toEqual(['live_chat','live_chat','live_chat']);
    expect(activities[1].actor).toBe('Alex');expect(activities[2].title).toBe('Automated chat message');
    await expect(page.locator('[data-nexa-activity-id="live-chat-1"]')).toContainText('Question <img src=x onerror="window.chatXss=true">');
    expect(await page.evaluate(()=>window.chatXss)).toBeUndefined();
    await expect(page.locator('main img,main [data-nexa-activity-actions],main [data-nexa-activity-comment-form]')).toHaveCount(0);
    await expect(page.locator('main .fa-comments')).toHaveCount(3);
    await expect(page.locator('main .nexa-activity-card').first()).toHaveCSS('display','block');
});
