const {test, expect} = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');

test('A/B canvas exposes weighted distinct routes and persisted branch history', async ({page}, info) => {
    const template = fs.readFileSync(path.join(root, 'espocrm/client/custom/res/templates/automation/workspace.tpl'), 'utf8');
    const css = fs.readFileSync(path.join(root, 'espocrm/client/custom/css/automation-workspace.css'), 'utf8');
    await page.setContent(`<meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0;font:14px Arial}.form-control{box-sizing:border-box;width:100%;padding:8px;border:1px solid #9db5aa;border-radius:4px}.btn{padding:8px;border:1px solid #a6bab0;border-radius:4px;background:white}[hidden]{display:none!important}${css}</style>${template}`);
    await page.evaluate(() => {window.define = (name, deps, factory) => {window.WorkflowView = factory(class {constructor() {this.options = {};}});};});
    await page.addScriptTag({content: fs.readFileSync(path.join(root, 'espocrm/client/custom/src/views/automation/workspace.js'), 'utf8')});
    await page.evaluate(() => {
        window.Espo = {Ajax:{getRequest:async () => ({runs:[],events:[{eventType:'step.completed',nodeId:'split',actorUserId:'admin',createdAt:'2026-10-12 10:00:00',payload:{abVariant:'B',weightA:35}}]})}};
        const view = window.view = new WorkflowView(); view.element = document.querySelector('main'); view.setPageTitle = () => {}; view.setup(); view.once = () => {};
        view.workspace = {supportedActions:[],supportedTriggers:['manual'],conditionFields:[],lifecycleOptions:[],list:[]};
        view.current = {id:'workflow',name:'Customer journey experiment',status:'draft',draftRevision:1,definition:{schemaVersion:1,trigger:{event:'manual',reenrollment:'after_completion'},entry:'split',nodes:[{id:'split',type:'ab_split',weightA:50,yes:'a',no:'b'},{id:'a',type:'end'},{id:'b',type:'end'}]}};
        for (const [binding,method] of Object.entries(view.events)) {const split = binding.indexOf(' '), type = binding.slice(0,split), selector = binding.slice(split+1); view.element.addEventListener(type,event => {const node = event.target.closest(selector);if (node) view[method]({currentTarget:node,preventDefault:()=>event.preventDefault()});});}
        view.renderEditor();
    });
    await expect(page.getByRole('button',{name:'A/B split step',exact:true})).toBeVisible();
    await expect(page.getByRole('button',{name:'Connect variant A branch from split',exact:true})).toBeVisible();
    await expect(page.getByRole('button',{name:'Connect variant B branch from split',exact:true})).toBeVisible();
    await page.getByRole('spinbutton',{name:'Variant A (%)',exact:true}).fill('35');
    await page.getByRole('spinbutton',{name:'Variant A (%)',exact:true}).press('Tab');
    expect(await page.evaluate(() => view.current.definition.nodes[0].weightA)).toBe(35);
    await expect(page.getByRole('combobox',{name:'Variant A branch',exact:true})).toHaveValue('a');
    await expect(page.getByRole('combobox',{name:'Variant B branch',exact:true})).toHaveValue('b');
    await expect(page.locator('.nw-journey-edges text')).toHaveText(['A','B']);
    await page.evaluate(async () => {await view.history();});
    await expect(page.getByText('step.completed / variant B (A 35%)',{exact:true})).toBeVisible();
    await info.attach('workflow-ab-canvas.png',{body:await page.screenshot({path:path.join(root,`test-results/workflow-ab-canvas-${info.project.name}.png`)}),contentType:'image/png'});
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});
