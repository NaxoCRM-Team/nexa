const {test, expect} = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');

async function mount(page) {
    const template = fs.readFileSync(path.join(root, 'espocrm/client/custom/res/templates/email-experiment/workspace.tpl'), 'utf8');
    const css = fs.readFileSync(path.join(root, 'espocrm/client/custom/css/email-experiments.css'), 'utf8');
    await page.setContent(`<meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0;font:14px Arial}*{box-sizing:border-box}[hidden]{display:none!important}.btn{padding:8px 12px;border:1px solid #c8d4d0;background:#fff;cursor:pointer}.form-control{width:100%;padding:8px;border:1px solid #b6c9c1;height:38px}.table{width:100%;border-collapse:collapse}.table th,.table td{padding:10px;text-align:left}${css}</style>${template}`);
    await page.evaluate(() => { window.define = (name, deps, factory) => { if (Array.isArray(name)) window.WorkspaceTable = deps(); else window.ExperimentView = factory(class {setPageTitle() {} getStorage() {return {get:() => window.tableState || {},set:(group,key,state) => {window.tableState = state;}};}},window.WorkspaceTable); }; });
    await page.addScriptTag({content: fs.readFileSync(path.join(root, 'espocrm/client/custom/src/workspace-table.js'), 'utf8')});
    await page.addScriptTag({content: fs.readFileSync(path.join(root, 'espocrm/client/custom/src/views/email-experiment/workspace.js'), 'utf8')});
    await page.evaluate(() => {
        const sources = [{id:'a',name:'Service newsletter A',version:2,campaignId:'campaign',purposeId:'purpose'},{id:'b',name:'Service newsletter B',version:3,campaignId:'campaign',purposeId:'purpose'}];
        window.requests = [];
        window.catalog = {sources, experiments:[{id:'fixture',name:'Autumn customer newsletter',status:'draft',testPercent:20,holdoutPercent:10,winner:null,createdAt:'2026-10-12 10:00:00'}, {id:'older',name:'Customer reactivation',status:'completed',testPercent:30,holdoutPercent:0,winner:'A',createdAt:'2026-10-11 10:00:00'}]};
        window.result = {experiment:{id:'fixture',name:'Autumn customer newsletter',status:'awaiting_winner',decision_due_at:'2020-01-01 10:00:00'},variants:[{key:'A',version:2},{key:'B',version:3}],metrics:{A:{assigned:100,queued:100,delivered:95,failed:5,uniqueClicks:17,uniqueClickRate:17,recordedOpens:80},B:{assigned:100,queued:100,delivered:99,failed:1,uniqueClicks:23,uniqueClickRate:23,recordedOpens:85}},cohorts:[{cohort:'test',status:'queued',count:200},{cohort:'holdout',status:'pending',count:100},{cohort:'rollout',status:'pending',count:700}],history:[{type:'test_queued',occurredAt:'2026-10-12 10:01:00'},{type:'started',occurredAt:'2026-10-12 10:00:00'}]};
        window.Espo = {Ajax:{getRequest:async url => url.endsWith('/workspace') ? structuredClone(catalog) : structuredClone(result),postRequest:async (url,body) => {requests.push({url,body}); if(window.reject) throw new Error('Both variants must use the same campaign.'); return {id:'fixture'};}}};
        const view = window.view = new ExperimentView(); view.element = document.querySelector('.nexa-experiments'); view.setup();
        for (const [binding,method] of Object.entries(view.events)) {
            const split = binding.indexOf(' '), event = binding.slice(0,split), selector = binding.slice(split+1);
            view.element.addEventListener(event, original => {const target = original.target.closest(selector); if(target) view[method]({currentTarget:target,preventDefault:()=>original.preventDefault()});});
        }
        view.load();
    });
    await expect(page.getByText('Total 2')).toBeVisible();
}

test('Experiment catalogue, exact approved variants and field errors work without page reload', async ({page}, info) => {
    await mount(page);
    await page.getByRole('searchbox', {name:'Search experiments'}).fill('reactivation');
    await expect(page.locator('[data-rows] tr')).toHaveCount(1); await expect(page.getByText('Total 1')).toBeVisible();
    await page.getByRole('button', {name:'New experiment',exact:true}).click();
    await expect(page.getByRole('textbox',{name:'Name',exact:true})).toBeFocused();
    await page.getByRole('textbox',{name:'Name',exact:true}).fill('Customer test');
    await page.getByRole('combobox',{name:'Variant A',exact:true}).selectOption('a');
    await page.getByRole('combobox',{name:'Variant B',exact:true}).selectOption('b');
    await page.getByRole('spinbutton',{name:'Test audience (%)'}).fill('40');
    await page.getByRole('spinbutton',{name:'Holdout (%)'}).fill('10');
    await page.evaluate(() => {window.reject = true;});
    await page.getByRole('button',{name:'Create experiment',exact:true}).click();
    await expect(page.getByRole('alert')).toHaveText('Both variants must use the same campaign.');
    await expect(page.getByRole('button',{name:'Create experiment',exact:true})).toBeEnabled();
    await page.evaluate(() => {window.reject = false;});
    await page.getByRole('button',{name:'Create experiment',exact:true}).click();
    await expect(page.locator('[data-create]')).toBeHidden();
    const request = await page.evaluate(() => requests[0].body);
    expect(request).toEqual({name:'Customer test',sourceA:'a',versionA:2,sourceB:'b',versionB:3,testPercent:40,holdoutPercent:10,windowMinutes:1440});
    await expect(page.getByRole('heading',{name:'Autumn customer newsletter',exact:true})).toBeVisible();
    await info.attach('email-experiment.png',{body:await page.screenshot({path:path.join(root,`test-results/email-experiment-${info.project.name}.png`)}),contentType:'image/png'});
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});

test('Experiment results require explicit winner, retain holdout and escape audit data', async ({page}) => {
    await mount(page);
    await page.getByRole('button',{name:'Autumn customer newsletter',exact:true}).click();
    await expect(page.getByRole('columnheader',{name:'Unique clicks',exact:true})).toBeVisible();
    await expect(page.getByText('holdout / pending:')).toBeVisible();
    await expect(page.getByRole('button',{name:'Select winner and roll out'})).toBeVisible();
    await page.getByRole('combobox',{name:'Winning variant'}).selectOption('B');
    await page.getByRole('textbox',{name:'Decision reason'}).fill('Reviewed unique click rate, not proxy opens.');
    await page.getByRole('button',{name:'Select winner and roll out'}).click();
    expect(await page.evaluate(() => requests.at(-1).body)).toEqual({action:'winner',variant:'B',reason:'Reviewed unique click rate, not proxy opens.'});
    await page.evaluate(async () => { result.experiment.name = '<img src=x onerror="window.attacked=1">'; result.history[0].type = '<script>window.attacked=1</script>'; await view.renderDetail('fixture'); });
    await expect(page.locator('[data-detail] img')).toHaveCount(0); expect(await page.evaluate(() => !!window.attacked)).toBe(false);
    await page.evaluate(async () => {result.experiment.decision_due_at='2099-01-01 10:00:00';await view.renderDetail('fixture');});
    await expect(page.getByRole('button',{name:'Select winner and roll out'})).toHaveCount(0);
});

test('Experiment catalogue, creation and results have accessible controls', async ({page}) => {
    await mount(page);
    const check = async () => {
        const result = await new AxeBuilder({page}).include('.nexa-experiments').withTags(['wcag2a','wcag2aa','wcag21aa']).analyze();
        expect(result.violations).toEqual([]);
    };
    await check();
    await page.getByRole('button',{name:'New experiment',exact:true}).click();
    await expect(page.getByRole('textbox',{name:'Name',exact:true})).toBeFocused();
    await check();
    await page.getByRole('button',{name:'Cancel',exact:true}).click();
    await page.getByRole('button',{name:'Autumn customer newsletter',exact:true}).click();
    await check();
});
