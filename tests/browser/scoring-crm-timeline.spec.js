const {test,expect}=require('@playwright/test');
const fs=require('node:fs');
const path=require('node:path');
const root=path.resolve(__dirname,'../..');

test('Contact timeline consumes canonical score history with model and actor details',async({page})=>{
    await page.setContent('<main data-nexa-contact-workspace></main>');
    await page.evaluate(()=>{
        window.define=(name,deps,factory)=>{window.ContactView=factory(class{},null,null,null,null,{hydrate(){}},{},class{});};
        window.Espo={Ajax:{getRequest:async()=>({timeline:[{id:'score-event',source_entity_type:'ScoringEvaluation',event_type:'score.projected',source_occurred_at:'2026-10-14 10:00:00',summary:'Score changed from 10 to 30 - Customer fit',metadata:{modelName:'Customer fit',version:2,previousScore:10,score:30,band:'Engaged',actorName:'Alex'}}]})}};
    });
    await page.addScriptTag({content:fs.readFileSync(path.join(root,'espocrm/client/custom/src/views/contact/record/detail-workspace.js'),'utf8')});
    const result=await page.evaluate(async()=>{
        const view=new ContactView();view.model={id:'contact'};view.element=document.querySelector('main');view.contactNoteDate=value=>value;view.renderContactActivities=()=>{};
        await view.loadContactBehaviorTimeline();return view.contactBehaviorActivities;
    });
    expect(result).toHaveLength(1);expect(result[0]).toMatchObject({id:'scoring-score-event',title:'Score updated',type:'other'});
    expect(result[0].behaviorDetails).toEqual([['Model','Customer fit'],['Version',2],['Previous score',10],['Score',30],['Band','Engaged'],['Recorded by','Alex']]);
    expect(result[0].text).toContain('10 to 30');expect(result[0]).not.toHaveProperty('href');
});

test('Account score history remains immutable and has no nonexistent native record route',async({page})=>{
    await page.setContent('<main></main>');
    await page.evaluate(()=>{window.define=(name,deps,factory)=>{window.AccountView=factory(class{},null,{hydrate(){}},{},class{});};});
    await page.addScriptTag({content:fs.readFileSync(path.join(root,'espocrm/client/custom/src/views/account/record/detail-workspace.js'),'utf8')});
    await page.evaluate(async()=>{
        const view=new AccountView();view.model={id:'account'};view.element=document.querySelector('main');view.collapsedAccountEngagementIds=new Set();
        view.getModelFactory=()=>{throw new Error('Audit entries must not use native entity editing.');};view.getHelper=()=>({sanitizeHtml:value=>value});view.engagementDate=record=>record.createdAt;
        const record={id:'event',_entityType:'ScoringEvaluation',name:'Score changed from 5 to 20 - Company fit',createdByName:'Alex',createdAt:'2026-10-14 10:00:00',status:'score.projected'};
        await view.prepareEngagementPermissions([record],'ScoringEvaluation');document.querySelector('main').append(view.engagementRecord(record));
    });
    await expect(page.locator('.nexa-activity-heading strong')).toHaveText('Score changed from 5 to 20 - Company fit');
    await expect(page.locator('[data-subtitle]')).toHaveText('Primary score updated');
    expect(await page.locator('[data-nexa-company-engagement-actions],a,.nexa-company-comment-footer').count()).toBe(0);
    await expect(page.locator('[data-owner]')).toHaveText('Recorded by Alex');
    await expect(page.locator('time')).toHaveText('2026-10-14 10:00:00');
});
