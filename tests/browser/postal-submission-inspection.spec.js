const {test,expect} = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname,'../..');

test('Held Postal inspection retains failures and uses an exact read-only evidence request', async ({page}) => {
    const css = fs.readFileSync(path.join(root,'espocrm/client/custom/css/marketing-email-workspace.css'),'utf8');
    await page.setContent(`<meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0;font:14px Arial}.form-control{box-sizing:border-box;width:100%}.btn{padding:8px}[hidden]{display:none!important}${css}</style><main><button id="review" data-id="source">Review</button></main>`);
    await page.evaluate(() => {window.define=(name,deps,factory)=>{window.MarketingView=factory(class {});};});
    await page.addScriptTag({content:fs.readFileSync(path.join(root,'espocrm/client/custom/src/views/marketing-email/workspace.js'),'utf8')});
    await page.evaluate(async () => {
        window.requests=[]; window.successes=[]; window.failInspection=true;
        window.Espo={Ui:{success:message=>successes.push(message),error:message=>{throw new Error(message);}},Ajax:{
            getRequest:async()=>({items:[{id:'held',recipient:'customer@example.test',createdAt:'2026-10-12 12:00:00',providerRecordId:41}]}),
            postRequest:async(url,input)=>{requests.push({url,input}); if(failInspection)throw new Error('Evidence mismatch. Reservation remains held.');},
        }};
        const view=window.view=new MarketingView(); view.element=document.querySelector('main');view.once=()=>{};view.dateTime=value=>value;view.load=async()=>{};
        await view.openSubmissionReview({currentTarget:document.querySelector('#review')});
    });
    const dialog=page.getByRole('dialog',{name:'Review held submission'});
    await expect(dialog).toBeVisible();
    await expect(dialog.getByRole('spinbutton',{name:'Postal record number'})).toHaveValue('41');
    const bounds=await dialog.boundingBox();
    expect(bounds.x).toBeGreaterThanOrEqual(0);expect(bounds.x+bounds.width).toBeLessThanOrEqual(page.viewportSize().width+1);
    await dialog.getByRole('button',{name:'Inspect Postal'}).click();
    await expect(dialog.getByRole('alert')).toHaveText('Evidence mismatch. Reservation remains held.');
    await expect(dialog.getByRole('button',{name:'Record resolution'})).toBeEnabled();
    expect(await page.evaluate(()=>requests[0])).toEqual({url:'Nexa/marketing-emails/source/submission-inspection',input:{messageId:'held',providerRecordId:41}});
    await dialog.getByRole('spinbutton',{name:'Postal record number'}).fill('0');
    await dialog.getByRole('button',{name:'Inspect Postal'}).click();
    await expect(dialog.getByRole('alert')).toHaveText('Enter a positive Postal record number.');
    expect(await page.evaluate(()=>requests.length)).toBe(1);
    await dialog.getByRole('spinbutton',{name:'Postal record number'}).fill('41');
    await page.evaluate(()=>{failInspection=false;});
    await dialog.getByRole('button',{name:'Inspect Postal'}).click();
    await expect(dialog).toHaveCount(0);
    expect(await page.evaluate(()=>successes)).toEqual(['Provider evidence verified. No message was resent.']);
});
