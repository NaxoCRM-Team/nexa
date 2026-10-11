define('custom:views/automation/workspace', ['view'], Dep => class extends Dep {
    template = 'custom:automation/workspace';
    events = {
        'click [data-action="refresh"]': 'load', 'click [data-action="new"]': 'newWorkflow',
        'input [data-search]': 'renderList', 'click [data-workflow]': 'openWorkflow',
        'input [data-name]': 'changed', 'change [data-policy]': 'changed', 'change [data-entry]': 'changed', 'change [data-trigger]': 'changed', 'input [data-page-url]': 'changed',
        'click [data-action="add"]': 'addStep', 'click [data-remove]': 'removeStep', 'change [data-node-input]': 'changeNode',
        'click [data-action="save"]': 'save', 'click [data-action="publish"]': 'publish', 'click [data-control]': 'control',
        'click [data-action="history"]': 'history', 'click [data-action="enroll"]': 'chooseContact',
        'click [data-owner]': 'chooseOwner', 'click [data-cancel-run]': 'cancelRun',
        'click [data-add-condition]': 'addCondition', 'click [data-remove-condition]': 'removeCondition',
        'change [data-trigger-field]': 'changed', 'change [data-trigger-list]': 'changed', 'input [data-trigger-threshold]': 'changed',
        'click [data-native-record]':'chooseNativeRecord',
        'click [data-review-webhook]':'reviewWebhook',
    };
    setup() { this.setPageTitle('Workflows'); this.sequence=0;this.busy=false;this.dirty=false; }
    afterRender() {this.once('remove',()=>{this.sequence++;this.removed=true;});this.load();}
    escape(value) {return String(value ?? '').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
    label(value) {return String(value).replace(/([a-z])([A-Z])/g,'$1 $2').replace(/_/g,' ').replace(/^./,c=>c.toUpperCase());}
    triggerLabel(value) {return ({manual:'Manual enrollment','form.submitted':'Form submitted','page.viewed':'Website visit','asset.downloaded':'Asset downloaded','email.replied':'Recorded email reply','email.opened':'Email opened','email.clicked':'Email clicked','contact.created':'Contact created','contact.updated':'Contact updated','contact.field_changed':'Contact property changed','lifecycle.changed':'Lifecycle changed','score.reached':'Score threshold reached','segment.entered':'Contact joins a list','segment.exited':'Contact leaves a list'})[value] || this.label(value);}
    requestKey() {return Array.from(crypto.getRandomValues(new Uint8Array(16)),byte=>byte.toString(16).padStart(2,'0')).join('');}
    message(text,error=false) {const el=this.element.querySelector('[data-message]');el.textContent=text;el.setAttribute('role',error?'alert':'status');}
    alive(request) {return !this.removed && request===this.sequence && this.element?.isConnected;}
    discard() {return !this.dirty || window.confirm('Discard unsaved workflow changes?');}
    async load() {
        if(this.busy || !this.discard())return;const request=++this.sequence;this.message('Loading workflows...');
        try {const data=await Espo.Ajax.getRequest('Nexa/workflows');if(!this.alive(request))return;this.workspace=data;this.current=null;this.dirty=false;this.renderList();this.renderEditor();this.message('');}
        catch(error){if(this.alive(request))this.message(error?.status===403?'An active tenant administrator is required.':'Workflows could not be loaded. Try again.',true);}
    }
    renderList() {
        const search=this.element.querySelector('[data-search]').value.toLowerCase().trim();
        const items=(this.workspace?.list || []).filter(item=>item.name.toLowerCase().includes(search));
        this.element.querySelector('[data-list]').innerHTML=items.map(item=>`<button type="button" data-workflow="${this.escape(item.id)}" aria-current="${item.id===this.current?.id}">${this.escape(item.name)}<small>${this.escape(this.label(item.status))}${item.publishedVersion?` &middot; Version ${Number(item.publishedVersion)}`:''}</small></button>`).join('') || '<p>No workflows found.</p>';
    }
    async openWorkflow(event) {
        if(this.busy || !this.discard())return;const request=++this.sequence;const id=event.currentTarget.dataset.workflow;
        this.message('Loading workflow...');
        try {const result=await Espo.Ajax.getRequest(`Nexa/workflows/${encodeURIComponent(id)}`);if(!this.alive(request))return;this.current=result;this.dirty=false;this.ownerNames={};this.renderList();this.renderEditor();this.message('');}
        catch(error){if(this.alive(request))this.message('The workflow could not be opened.',true);}
    }
    newWorkflow() {
        if(this.busy || !this.workspace || !this.discard())return;this.sequence++;
        try{localStorage.removeItem(`nexa:workflow-layout:${location.pathname}:draft`);}catch(error){}
        this.current={name:'',status:'draft',definition:{schemaVersion:1,trigger:{event:'manual',reenrollment:'never'},entry:'end',nodes:[{id:'end',type:'end'}]}};
        this.dirty=true;this.ownerNames={};this.renderList();this.renderEditor();this.element.querySelector('[data-name]').focus();
    }
    changed(event) {
        if(!this.current || this.busy || this.current.status==='archived')return;
        this.current.name=this.element.querySelector('[data-name]').value;
        this.current.definition.trigger.reenrollment=this.element.querySelector('[data-policy]').value;
        this.current.definition.entry=this.element.querySelector('[data-entry]').value;
        this.current.definition.trigger.event=this.element.querySelector('[data-trigger]').value;
        const trigger=this.current.definition.trigger,pageUrl=this.element.querySelector('[data-page-url]')?.value || '';
        if(trigger.event==='page.viewed' && pageUrl)trigger.pageUrl=pageUrl;else delete trigger.pageUrl;
        if(event?.currentTarget?.matches('[data-trigger]')){
            for(const key of ['field','segmentId','threshold'])delete trigger[key];
            if(trigger.event==='contact.field_changed')trigger.field=(this.workspace.conditionFields || []).find(field=>!field.name.includes(':'))?.name || '';
            if(trigger.event==='date.reached')trigger.field=(this.workspace.conditionFields || []).find(field=>field.name.startsWith('custom:') && ['date','datetime'].includes(field.type))?.name || '';
            if(['segment.entered','segment.exited'].includes(trigger.event))trigger.segmentId='';
            if(trigger.event==='score.reached')trigger.threshold=50;
            this.dirty=true;this.renderEditor();return;
        }
        if(['contact.field_changed','date.reached'].includes(trigger.event))trigger.field=this.element.querySelector('[data-trigger-field]').value;
        if(['segment.entered','segment.exited'].includes(trigger.event))trigger.segmentId=this.element.querySelector('[data-trigger-list]').value;
        if(trigger.event==='score.reached'){const value=this.element.querySelector('[data-trigger-threshold]').value;trigger.threshold=value===''?null:Number(value);}
        this.dirty=true;this.updateButtons();
    }
    options(items,value) {return items.map(item=>{const [id,label]=Array.isArray(item)?item:[item,this.label(item)];return `<option value="${this.escape(id)}" ${String(id)===String(value)?'selected':''}>${this.escape(label)}</option>`;}).join('');}
    select(label,key,items,value) {return `<label>${this.escape(label)}<select class="form-control" data-node-input="${key}">${this.options(items,value)}</select></label>`;}
    input(label,key,value,attrs='') {return `<label>${this.escape(label)}<input class="form-control" data-node-input="${key}" value="${this.escape(value)}" ${attrs}></label>`;}
    nodeTitle(node) {return ({end:'Finish',delay:'Delay',wait_until:'Wait until date and time',branch:'Branch',wait_condition:'Wait for a condition',business_hours:'Working hours','lifecycle.set':'Set lifecycle stage','owner.assign':'Assign owner','task.create':'Create task','segment.add':'Add to static list','segment.remove':'Remove from static list','case.create':'Create Case','notification.create':'Notify staff','opportunity.create':'Create Opportunity','email.send':'Send approved marketing email','score.increase':'Increase score','score.decrease':'Decrease score','tags.add':'Add tag','tags.remove':'Remove tag','contact.update':'Update Contact property','account.update':'Update primary Account','opportunity.update':'Update associated Opportunity','team.notify':'Notify team','webhook.send':'Send signed webhook'})[node.action || node.type] || 'Unsupported step';}
    displayNodes() {
        const nodes=this.current.definition.nodes,byId=new Map(nodes.map(node=>[node.id,node])),counts=new Map(nodes.map(node=>[node.id,0]));
        const edges=node=>[...new Set(['next','yes','no'].map(key=>node[key]).filter(id=>byId.has(id)))];
        for(const node of nodes)for(const id of edges(node))counts.set(id,counts.get(id)+1);
        const pending=nodes.filter(node=>counts.get(node.id)===0),result=[];
        while(pending.length){const node=pending.shift();result.push(node);for(const id of edges(node)){counts.set(id,counts.get(id)-1);if(counts.get(id)===0)pending.push(byId.get(id));}}
        // Drafts with cycles remain editable; publication still uses the authoritative validator.
        return [...result,...nodes.filter(node=>!result.includes(node))];
    }
    renderEditor() {
        const host=this.element.querySelector('[data-editor]');if(!this.current){host.innerHTML='<p>Select a workflow</p>';return;}
        const c=this.current,g=c.definition,display=this.displayNodes(),links=display.map((n,index)=>[n.id,`${index+1}. ${this.nodeTitle(n)}`]);
        const publishedTrigger=c.publishedTrigger || (c.publishedVersion?g.trigger.event:null);
        host.innerHTML=`<div class="nw-toolbar"><h2>${this.escape(c.name || 'New workflow')}</h2><div><button type="button" class="btn btn-default" data-action="save"><span class="fas fa-save" aria-hidden="true"></span> Save draft</button><button type="button" class="btn btn-primary" data-action="publish"><span class="fas fa-check" aria-hidden="true"></span> Publish</button>${c.publishedVersion?`<button type="button" class="btn btn-default" data-control="${c.status==='paused'?'published':'paused'}">${c.status==='paused'?'Resume':'Pause'}</button>${publishedTrigger==='manual'?'<button type="button" class="btn btn-default" data-action="enroll">Enroll Contact</button>':''}`:''}${c.id?'<button type="button" class="btn btn-default" data-control="archived">Archive</button>':''}</div></div>
            <small>${this.escape(this.label(c.status))}${c.publishedVersion?` &middot; Published version ${Number(c.publishedVersion)}`:''}</small>
            <div class="nw-meta"><label>Workflow name<input class="form-control" data-name maxlength="255" required value="${this.escape(c.name)}"></label><label>Reenrollment<select class="form-control" data-policy>${this.options([['never','Once per Contact'],['after_completion','After successful completion']],g.trigger.reenrollment)}</select></label><label>Enrollment<select class="form-control" data-trigger>${this.options([...new Set([...(this.workspace.supportedTriggers || ['manual']),g.trigger.event])].map(event=>[event,this.triggerLabel(event)]),g.trigger.event)}</select></label><label>Starting step<select class="form-control" data-entry>${this.options(links,g.entry)}</select></label></div>
            ${g.trigger.event==='page.viewed'?`<label>Page URL (optional)<input class="form-control" data-page-url type="url" maxlength="2048" value="${this.escape(g.trigger.pageUrl || '')}"></label>`:''}
            ${g.trigger.event==='contact.field_changed'?`<label>Enrollment property<select class="form-control" data-trigger-field>${this.options((this.workspace.conditionFields || []).filter(field=>!field.name.includes(':')).map(field=>[field.name,field.label || this.label(field.name)]),g.trigger.field)}</select></label>`:''}
            ${g.trigger.event==='date.reached'?`<label>Enrollment date property<select class="form-control" data-trigger-field>${this.options((this.workspace.conditionFields || []).filter(field=>field.name.startsWith('custom:') && ['date','datetime'].includes(field.type)).map(field=>[field.name,field.label || this.label(field.name)]),g.trigger.field)}</select></label>`:''}
            ${['segment.entered','segment.exited'].includes(g.trigger.event)?`<label>Enrollment list<select class="form-control" data-trigger-list>${this.options([['','Choose a list'],...(this.workspace.enrollmentLists || []).map(list=>[list.id,list.name])],g.trigger.segmentId)}</select></label>`:''}
            ${g.trigger.event==='score.reached'?`<label>Enrollment score threshold<input class="form-control" data-trigger-threshold type="number" min="0" max="1000000" required value="${this.escape(g.trigger.threshold)}"></label>`:''}
            <section class="nw-journey" aria-label="Journey canvas"><div class="nw-journey-board" data-journey-board></div></section>
            <div class="nw-canvas" aria-label="Workflow steps">${display.map(n=>this.renderNode(n,links)).join('')}</div>
            <div class="nw-add"><select class="form-control" data-step-type aria-label="New step type">${this.options([['delay','Delay'],['wait_until','Wait until date and time'],['branch','Branch'],['wait_condition','Wait for a condition'],['business_hours','Working hours'],...(this.workspace.supportedActions || []).map(action=>[action,this.nodeTitle({action})])],'delay')}</select><button type="button" class="btn btn-default" data-action="add"><span class="fas fa-plus" aria-hidden="true"></span> Add step</button></div>
            ${c.id?'<button type="button" class="btn btn-default" data-action="history"><span class="fas fa-history" aria-hidden="true"></span> Refresh history</button><section class="nw-history" data-history aria-label="Execution history"></section>':''}`;
        this.renderJourney();this.updateButtons();
    }
    renderJourney() {
        const previousBoard=this.element.querySelector('[data-journey-board]');if(!previousBoard || !this.current)return;
        const board=previousBoard.cloneNode(false);previousBoard.replaceWith(board);
        const nodes=this.displayNodes(),byId=new Map(nodes.map(node=>[node.id,node])),levels=new Map(nodes.map(node=>[node.id,0]));
        for(const node of nodes)for(const edge of ['next','yes','no'])if(byId.has(node[edge]))levels.set(node[edge],Math.max(levels.get(node[edge]),levels.get(node.id)+1));
        const rows=new Map(),key=`nexa:workflow-layout:${location.pathname}:${this.current.id || 'draft'}`;
        let saved=this.current.definition.layout || {};if(!this.current.definition.layout){try{saved=JSON.parse(localStorage.getItem(key) || '{}');}catch(error){}}
        const validPosition=p=>p && Number.isFinite(p.x) && Number.isFinite(p.y) && p.x>=0 && p.y>=0 && p.x<=25000 && p.y<=25000;
        const occupied=nodes.map(node=>saved?.[node.id]).filter(validPosition);
        const positions=new Map(nodes.map(node=>{
            const level=levels.get(node.id),row=rows.get(level) || 0;rows.set(level,row+1);
            const p=saved?.[node.id],valid=p && Number.isFinite(p.x) && Number.isFinite(p.y) && p.x>=0 && p.y>=0 && p.x<=25000 && p.y<=25000;
            if(valid)return [node.id,{x:p.x,y:p.y}];
            const position={x:Math.min(25000,24+level*260),y:24+row*130};
            while(occupied.some(other=>Math.abs(other.x-position.x)<230 && Math.abs(other.y-position.y)<100))position.y+=130;
            occupied.push(position);return [node.id,position];
        }));
        board.innerHTML=`<svg class="nw-journey-edges" aria-hidden="true"></svg>${nodes.map(node=>`<button type="button" class="nw-journey-node ${node.id===this.current.definition.entry?'nw-journey-entry':''}" data-journey-node="${this.escape(node.id)}" aria-label="${this.escape(this.nodeTitle(node))} step" title="${this.escape(this.nodeTitle(node))}" ${this.busy || this.current.status==='archived'?'disabled':''}><span class="fas ${node.type==='end'?'fa-flag-checkered':node.condition?'fa-code-branch':node.type==='action'?'fa-bolt':'fa-clock'}" aria-hidden="true"></span><strong>${this.escape(this.nodeTitle(node))}</strong><small>${node.id===this.current.definition.entry?'Start':node.type==='end'?'Finish':this.escape(this.label(node.type))}</small></button>${['next','yes','no'].filter(edge=>Object.hasOwn(node,edge)).map(edge=>`<button type="button" class="nw-journey-port nw-port-${edge}" data-connect-node="${this.escape(node.id)}" data-connect-edge="${edge}" aria-pressed="false" aria-label="Connect ${edge==='yes'?'matched branch':edge==='no'?'unmatched branch':'next step'} from ${this.escape(node.id)}" title="${edge==='yes'?'Connect matched branch':edge==='no'?'Connect unmatched branch':'Connect next step'}" ${this.busy || this.current.status==='archived'?'disabled':''}><span class="fas fa-arrow-right" aria-hidden="true"></span></button>`).join('')}`).join('')}`;
        const draw=()=>{
            const width=Math.max(600,...Array.from(positions.values(),p=>p.x+240)),height=Math.max(280,...Array.from(positions.values(),p=>p.y+110));
            board.style.width=`${width}px`;board.style.height=`${height}px`;
            for(const button of board.querySelectorAll('[data-journey-node]')){const p=positions.get(button.dataset.journeyNode);button.style.left=`${p.x}px`;button.style.top=`${p.y}px`;}
            for(const port of board.querySelectorAll('[data-connect-node]')){const p=positions.get(port.dataset.connectNode);port.style.left=`${p.x+198}px`;port.style.top=`${p.y+(port.dataset.connectEdge==='yes'?8:port.dataset.connectEdge==='no'?48:28)}px`;}
            const svg=board.querySelector('svg');svg.setAttribute('width',width);svg.setAttribute('height',height);
            svg.innerHTML=nodes.flatMap(node=>['next','yes','no'].filter(edge=>positions.has(node[edge])).map(edge=>{
                const from=positions.get(node.id),to=positions.get(node[edge]),x=from.x+210,y=from.y+40,endX=to.x,endY=to.y+40,bend=Math.max(35,Math.abs(endX-x)/2);
                return `<path class="nw-edge-${edge}" d="M ${x} ${y} C ${x+bend} ${y}, ${endX-bend} ${endY}, ${endX} ${endY}"/><polygon class="nw-edge-${edge}" points="${endX},${endY} ${endX-8},${endY-5} ${endX-8},${endY+5}"/>${edge!=='next'?`<text x="${x+10}" y="${y+(edge==='yes'?-8:18)}">${edge==='yes'?'Yes':'No'}</text>`:''}`;
            })).join('');
        };
        const store=()=>{
            this.current.definition.layout=Object.fromEntries(Array.from(positions,([id,p])=>[id,{x:Math.round(p.x),y:Math.round(p.y)}]));
            this.dirty=true;this.updateButtons();
        };
        let drag=null,suppressClick=false,connection=null;
        board.addEventListener('pointerdown',event=>{
            const button=event.target.closest('[data-journey-node]');if(!button || button.disabled || event.button!==0)return;
            const p=positions.get(button.dataset.journeyNode);drag={id:button.dataset.journeyNode,pointer:event.pointerId,x:event.clientX,y:event.clientY,start:{...p},moved:false};
            button.setPointerCapture(event.pointerId);button.focus();
        });
        board.addEventListener('pointermove',event=>{
            if(!drag || event.pointerId!==drag.pointer)return;
            const dx=event.clientX-drag.x,dy=event.clientY-drag.y;if(Math.abs(dx)+Math.abs(dy)<4 && !drag.moved)return;
            drag.moved=true;positions.set(drag.id,{x:Math.max(0,Math.min(25000,drag.start.x+dx)),y:Math.max(0,Math.min(25000,drag.start.y+dy))});draw();
        });
        const stop=()=>{suppressClick=Boolean(drag?.moved);if(drag?.moved)store();drag=null;};
        board.addEventListener('pointerup',stop);board.addEventListener('pointercancel',stop);
        board.addEventListener('keydown',event=>{
            if(event.key==='Escape' && connection){event.preventDefault();connection=null;for(const port of board.querySelectorAll('[data-connect-node]'))port.setAttribute('aria-pressed','false');return;}
            const button=event.target.closest('[data-journey-node]');if(!button || button.disabled || !['ArrowLeft','ArrowRight','ArrowUp','ArrowDown'].includes(event.key))return;
            event.preventDefault();const p=positions.get(button.dataset.journeyNode),amount=event.shiftKey?50:20;
            positions.set(button.dataset.journeyNode,{x:Math.max(0,Math.min(25000,p.x+(event.key==='ArrowRight'?amount:event.key==='ArrowLeft'?-amount:0))),y:Math.max(0,Math.min(25000,p.y+(event.key==='ArrowDown'?amount:event.key==='ArrowUp'?-amount:0)))});draw();store();
        });
        board.addEventListener('click',event=>{
            if(suppressClick){suppressClick=false;return;}
            const port=event.target.closest('[data-connect-node]');
            if(port && !port.disabled){connection={id:port.dataset.connectNode,edge:port.dataset.connectEdge};for(const other of board.querySelectorAll('[data-connect-node]'))other.setAttribute('aria-pressed',String(other===port));return;}
            const button=event.target.closest('[data-journey-node]');if(!button)return;
            if(connection && !button.disabled){
                const target=button.dataset.journeyNode,visited=new Set(),reachesSource=id=>{
                    if(id===connection.id)return true;if(visited.has(id))return false;visited.add(id);
                    const node=byId.get(id);return node && ['next','yes','no'].some(edge=>node[edge] && reachesSource(node[edge]));
                };
                if(reachesSource(target)){this.message('This connection would create a loop.',true);return;}
                byId.get(connection.id)[connection.edge]=target;store();this.renderEditor();this.message('Connection updated.');return;
            }
            const editor=Array.from(this.element.querySelectorAll('[data-node]')).find(element=>element.dataset.node===button.dataset.journeyNode);
            editor?.scrollIntoView({block:'nearest',behavior:'smooth'});editor?.querySelector('input,select,button')?.focus({preventScroll:true});
        });
        draw();
    }
    renderNode(node,links) {
        const destinations=links.filter(([id])=>id!==node.id);let fields='';
        if(node.type==='delay')fields+=this.input('Delay (seconds)','seconds',node.seconds,'type="number" min="1" max="31536000"');
        if(node.type==='wait_until')fields+=this.input('Wait until (with time zone)','at',node.at,'maxlength="30" placeholder="2028-02-29T09:00:00Z"');
        if(node.condition){const rules=node.condition.conditions || [node.condition];
            if(node.condition.conditions)fields+=this.select('Match conditions','matchMode',[['all','All conditions'],['any','Any condition'],['sequence','Recorded events in order']],node.condition.matchMode);
            fields+=rules.map((rule,index)=>`<div class="nw-condition" data-condition="${index}">${this.renderCondition(rule)}${rules.length>1?`<button type="button" class="btn btn-default" data-remove-condition="${index}" title="Remove condition" aria-label="Remove condition ${index+1}"><span class="fas fa-minus" aria-hidden="true"></span></button>`:''}</div>`).join('');
            if(rules.length<6)fields+='<button type="button" class="btn btn-default" data-add-condition><span class="fas fa-plus" aria-hidden="true"></span> Add condition</button>';
            fields+=this.select('If matched','yes',destinations,node.yes)+this.select(node.type==='wait_condition'?'If timeout':'If not matched','no',destinations,node.no);
            if(node.type==='wait_condition')fields+=this.input('Timeout (seconds)','timeoutSeconds',node.timeoutSeconds,'type="number" min="60" max="31536000"')+this.input('Check interval (seconds)','pollSeconds',node.pollSeconds,'type="number" min="60" max="86400"');
        }
        if(node.type==='business_hours'){const zones=[...new Set(['UTC',...(Intl.supportedValuesOf?.('timeZone') || ['Europe/London']),node.timezone])];
            fields+=this.select('Time zone','timezone',zones,node.timezone)+`<fieldset class="nw-weekdays"><legend>Working days</legend>${['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'].map((day,index)=>`<label><input type="checkbox" data-node-input="weekdays" value="${index+1}" ${node.weekdays.includes(index+1)?'checked':''}> ${day}</label>`).join('')}</fieldset>`+this.input('Opens','start',node.start,'type="time"')+this.input('Closes','end',node.end,'type="time"');}
        if(node.action==='email.send'){
            const sources=this.workspace.emailSources || [],source=sources.find(item=>item.id===node.settings.sourceId && item.version===node.settings.version);
            fields+=this.select('Approved email','emailSource',[['','Choose approved email'],...sources.map(item=>[`${item.id}:${item.version}`,`${item.name} (version ${item.version})`])],node.settings.sourceId?`${node.settings.sourceId}:${node.settings.version}`:'');
            for(const [name,limit] of Object.entries(source?.parameters || {})) {
                const binding=node.settings.parameterBindings?.[name],properties=(this.workspace.conditionFields || []).filter(field=>!field.name.includes(':') || field.name.startsWith('custom:'));
                fields+=this.select(`${this.label(name)} value source`,`emailBinding:${name}`,[['','Fixed text'],...properties.map(field=>[field.name,field.label || this.label(field.name)])],binding?.field || '');
                fields+=this.input(binding?`${this.label(name)} fallback (optional)`:this.label(name),binding?`emailFallback:${name}`:`emailParameter:${name}`,binding?.fallback ?? node.settings.parameters?.[name] ?? '',`maxlength="${Number(limit)}"`);
            }
        }
        if(node.action==='lifecycle.set')fields+=this.select('Lifecycle stage','stage',(this.workspace.lifecycleOptions || []).filter(Boolean),node.settings.stage);
        if(['score.increase','score.decrease'].includes(node.action))fields+=this.input('Score adjustment','scoreAmount',node.settings.amount,'type="number" min="1" max="1000"');
        if(['tags.add','tags.remove'].includes(node.action))fields+=this.input('Tag','tagText',node.settings.tag,'maxlength="80"');
        if(['contact.update','account.update','opportunity.update'].includes(node.action)){
            const type={ 'contact.update':'Contact','account.update':'Account','opportunity.update':'Opportunity'}[node.action],catalog=this.workspace.updateProperties?.[type] || [],field=catalog.find(item=>item.name===node.settings.field);
            fields+=this.select('Property to update','updateField',catalog.map(item=>[item.name,this.label(item.name)]),node.settings.field);
            fields+=field?.type==='enum'?this.select('New value','updateValue',field.options,node.settings.value):this.input('New value','updateValue',node.settings.value,field?.type==='date'?'type="date"':'maxlength="1000"');
        }
        if(node.action==='webhook.send')fields+=`<button type="button" class="btn btn-default" data-native-record="${this.escape(node.id)}" data-native-scope="Webhook">${this.escape(this.ownerNames?.[node.id] || node.settings.webhookId || 'Choose webhook')}</button>`;
        if(['team.notify','opportunity.update'].includes(node.action)){
            const scope=node.action==='team.notify'?'Team':'Opportunity',key=scope==='Team'?'teamId':'opportunityId';
            fields+=`<button type="button" class="btn btn-default" data-native-record="${this.escape(node.id)}" data-native-scope="${scope}">${this.escape(this.ownerNames?.[node.id] || node.settings[key] || `Choose ${scope.toLowerCase()}`)}</button>`;
            if(scope==='Team')fields+=this.input('Notification message','notificationMessage',node.settings.message,'maxlength="1000"');
        }
        if(node.action==='owner.assign')fields+=`<div class="nw-owner"><strong>Contact owner</strong><button type="button" class="btn btn-default" data-owner="${this.escape(node.id)}">${this.escape(this.ownerNames?.[node.id] || node.settings.ownerId || 'Choose owner')}</button></div>`;
        if(node.action==='opportunity.create'){
            const options=this.workspace.opportunityOptions || {},rows=options.stages || [],pipelines=Array.from(new Map(rows.map(row=>[row.pipelineId,[row.pipelineId,row.pipelineName]])).values());
            fields+=this.input('Opportunity name','opportunityName',node.settings.name || '','maxlength="255"')+`<div class="nw-owner"><strong>Opportunity owner</strong><button type="button" class="btn btn-default" data-owner="${this.escape(node.id)}">${this.escape(this.ownerNames?.[node.id] || node.settings.ownerId || 'Publishing administrator')}</button></div>`+this.select('Sales pipeline','opportunityPipeline',[['','Choose pipeline'],...pipelines],node.settings.pipelineId || '')+this.select('Opportunity stage','opportunityStage',[['','Choose stage'],...rows.filter(row=>row.pipelineId===node.settings.pipelineId).map(row=>row.stage)],node.settings.stage || '')+this.input('Opportunity amount','opportunityAmount',node.settings.amount || '','inputmode="decimal" maxlength="15"')+this.select('Opportunity currency','opportunityCurrency',[['','Choose currency'],...(options.currencies || [])],node.settings.currency || '')+this.input('Expected close date','opportunityCloseDate',node.settings.closeDate || '','type="date"');
        }
        if(node.action==='notification.create')fields+=this.input('Notification message','notificationMessage',node.settings.message || '','maxlength="1000"')+`<div class="nw-owner"><strong>Recipient</strong><button type="button" class="btn btn-default" data-owner="${this.escape(node.id)}">${this.escape(this.ownerNames?.[node.id] || node.settings.ownerId || 'Choose staff member')}</button></div>`;
        if(node.action==='task.create')fields+=this.input('Task name','taskName',node.settings.name,'maxlength="255"')+`<div class="nw-owner"><strong>Task owner</strong><button type="button" class="btn btn-default" data-owner="${this.escape(node.id)}">${this.escape(this.ownerNames?.[node.id] || node.settings.ownerId || 'Publishing administrator')}</button></div>`;
        if(node.action==='case.create')fields+=this.input('Case name','caseName',node.settings.name,'maxlength="255"')+`<div class="nw-owner"><strong>Case owner</strong><button type="button" class="btn btn-default" data-owner="${this.escape(node.id)}">${this.escape(this.ownerNames?.[node.id] || node.settings.ownerId || 'Publishing administrator')}</button></div>`+this.select('Case priority','casePriority',[['','Use native default'],...(this.workspace.caseOptions?.priority || [])],node.settings.priority || '')+this.select('Case category','caseCategory',(this.workspace.caseOptions?.category || []).map(value=>[value,value || 'No category']),node.settings.category || '');
        if(['segment.add','segment.remove'].includes(node.action))fields+=this.select('Static list','segmentId',[['','Choose a list'],...(this.workspace.staticLists || []).map(list=>[list.id,list.name])],node.settings.segmentId);
        if(node.type==='action' && node.action!=='email.send')fields+=this.input('Maximum attempts','maxAttempts',node.maxAttempts,'type="number" min="1" max="5"')+this.input('Retry interval (seconds)','retrySeconds',node.retrySeconds,'type="number" min="60" max="86400"');
        if(node.next)fields+=this.select('Next step','next',destinations,node.next);
        return `<article class="nw-step ${node.id===this.current.definition.entry?'nw-entry':''}" data-node="${this.escape(node.id)}"><div class="nw-step-heading"><h3>${this.escape(links.find(([id])=>id===node.id)?.[1] || this.nodeTitle(node))}</h3>${node.type!=='end'?`<button class="btn btn-default" type="button" data-remove="${this.escape(node.id)}" title="Remove step" aria-label="Remove ${this.escape(this.nodeTitle(node))}"><span class="fas fa-trash-alt" aria-hidden="true"></span></button>`:''}</div><div class="nw-fields">${fields}</div></article>`;
    }
    renderCondition(rule) {
        const field=this.workspace.conditionFields.find(f=>f.name===rule.field),numeric=['int','decimal'].includes(field?.type),behavior=field?.type==='behavior';
        let html=this.select('Contact property or activity','field',this.workspace.conditionFields.map(f=>[f.name,f.label || this.label(f.name)]),rule.field);
        const relative=['date','datetime'].includes(field?.type) && ['within_next_days','within_last_days'].includes(rule.operator);
        html+=this.select('Comparison','operator',behavior?[['within_days','Occurred within'],['not_within_days','Did not occur within'],['count_at_least','Event count is at least'],['count_at_most','Event count is at most'],['count_equals','Event count equals']]:['equals','not_equals','has_value','is_empty',...(numeric?['greater_than','less_than']:['date','datetime'].includes(field?.type)?['before','after','on_or_before','on_or_after',['within_next_days','Within the next (UTC)'],['within_last_days','Within the last (UTC)']]:[])],rule.operator);
        if(!['has_value','is_empty'].includes(rule.operator))html+=['enum','boolean'].includes(field?.type)?this.select('Value','value',field.options || [],rule.value):this.input(behavior || relative?'Days':field?.type==='datetime'?'Date and time (with time zone)':'Value','value',rule.value ?? '',relative?'type="number" min="0" max="365"':behavior?'type="number" min="1" max="365"':field?.type==='date'?'type="date"':field?.type==='datetime'?'maxlength="30" placeholder="2028-02-29T09:00:00Z"':numeric?'inputmode="decimal"':'maxlength="255"');
        if(rule.field==='behavior:page.viewed')html+=this.input('Exact page URL (optional)','conditionPageUrl',rule.pageUrl || '','type="url" maxlength="2048"');
        if(['behavior:email.opened','behavior:email.clicked'].includes(rule.field))html+=this.select('Campaign (optional)','conditionCampaign',[['','Any campaign'],...(this.workspace.campaigns || []).map(item=>[item.id,item.name])],rule.campaignId || '');
        if(behavior && rule.operator.startsWith('count_'))html+=this.input('Event count','count',rule.count ?? '','type="number" min="0" max="10000" step="1" required');
        return html;
    }
    addCondition(event) {
        if(this.busy || this.current?.status==='archived')return;
        const node=this.current.definition.nodes.find(n=>n.id===event.currentTarget.closest('[data-node]').dataset.node),rules=node.condition.conditions || [node.condition];
        if(rules.length>=6)return;
        node.condition={matchMode:node.condition.matchMode || 'all',conditions:[...rules,{field:this.workspace.conditionFields[0]?.name || 'firstName',operator:'has_value'}]};
        this.dirty=true;this.renderEditor();
    }
    removeCondition(event) {
        if(this.busy || this.current?.status==='archived')return;
        const node=this.current.definition.nodes.find(n=>n.id===event.currentTarget.closest('[data-node]').dataset.node),index=Number(event.currentTarget.dataset.removeCondition);
        if(!node.condition.conditions || node.condition.conditions.length<=1)return;
        node.condition.conditions.splice(index,1);if(node.condition.conditions.length===1)node.condition=node.condition.conditions[0];
        this.dirty=true;this.renderEditor();
    }
    updateButtons() {
        const c=this.current;if(!c)return;this.element.querySelector('[data-editor]').querySelectorAll('input,select,button').forEach(el=>{el.disabled=this.busy || c.status==='archived';});
        this.element.querySelector('[data-action="publish"]').disabled ||= !c.id || this.dirty;
        const enroll=this.element.querySelector('[data-action="enroll"]');if(enroll)enroll.disabled ||= c.status!=='published' || this.dirty;
        const history=this.element.querySelector('[data-action="history"]');if(history)history.disabled=this.busy;
    }
    addStep() {
        if(this.busy || !this.current || this.current.status==='archived')return;const g=this.current.definition;
        if(g.nodes.length>=100){this.message('A workflow can contain at most 100 steps.',true);return;}
        const type=this.element.querySelector('[data-step-type]').value,end=g.nodes.find(n=>n.type==='end');if(!end){this.message('A finishing step is required.',true);return;}
        const id=`step_${this.requestKey().slice(0,12)}`;let node={id,type,next:end.id};
        if(type==='delay')node.seconds=60;
        if(type==='wait_until')node.at=new Date(Date.now()+86400000).toISOString().replace(/\.\d{3}Z$/,'Z');
        if(['branch','wait_condition'].includes(type)){delete node.next;node.condition={field:this.workspace.conditionFields[0]?.name || 'firstName',operator:'has_value'};node.yes=end.id;node.no=end.id;if(type==='wait_condition'){node.timeoutSeconds=3600;node.pollSeconds=60;}}
        if(type==='business_hours')Object.assign(node,{timezone:'UTC',weekdays:[1,2,3,4,5],start:'09:00',end:'17:00'});
        if(this.workspace.supportedActions.includes(type))Object.assign(node,{type:'action',action:type,settings:type==='lifecycle.set'?{stage:this.workspace.lifecycleOptions.filter(Boolean)[0] || ''}:['task.create','case.create'].includes(type)?{name:''}:['segment.add','segment.remove'].includes(type)?{segmentId:''}:{ownerId:''},maxAttempts:1,retrySeconds:60});
        if(type==='email.send')node.settings={sourceId:'',version:1,parameters:{}};
        if(['score.increase','score.decrease'].includes(type))node.settings={amount:10};
        if(['tags.add','tags.remove'].includes(type))node.settings={tag:''};
        if(['contact.update','account.update','opportunity.update'].includes(type)){
            const scope={'contact.update':'Contact','account.update':'Account','opportunity.update':'Opportunity'}[type],property=this.workspace.updateProperties?.[scope]?.[0];
            node.settings={field:property?.name || '',value:property?.options?.[0] || ''};if(type==='opportunity.update')node.settings.opportunityId='';
        }
        if(type==='team.notify')node.settings={teamId:'',message:''};
        if(type==='webhook.send')node.settings={webhookId:''};
        if(type==='opportunity.create')node.settings={name:'',pipelineId:'',stage:'',amount:'',currency:this.workspace.opportunityOptions?.defaultCurrency || '',closeDate:''};
        for(const item of g.nodes)for(const edge of ['next','yes','no'])if(item[edge]===end.id)item[edge]=id;
        if(g.entry===end.id)g.entry=id;g.nodes.splice(g.nodes.indexOf(end),0,node);this.dirty=true;this.renderEditor();this.element.querySelector(`[data-node="${id}"] select, [data-node="${id}"] input`)?.focus();
    }
    removeStep(event) {
        if(this.busy || this.current?.status==='archived')return;const g=this.current.definition,id=event.currentTarget.dataset.remove,node=g.nodes.find(n=>n.id===id);if(!node || node.type==='end')return;
        const target=node.next || node.yes;for(const item of g.nodes)for(const edge of ['next','yes','no'])if(item[edge]===id)item[edge]=target;
        if(g.entry===id)g.entry=target;g.nodes=g.nodes.filter(n=>n.id!==id);if(g.layout)delete g.layout[id];this.dirty=true;this.renderEditor();
    }
    changeNode(event) {
        if(this.busy || this.current?.status==='archived')return;const input=event.currentTarget,node=this.current.definition.nodes.find(n=>n.id===input.closest('[data-node]').dataset.node),key=input.dataset.nodeInput;
        if(key==='conditionCampaign'){const condition=node.condition.conditions?node.condition.conditions[Number(input.closest('[data-condition]').dataset.condition)]:node.condition;if(input.value)condition.campaignId=input.value;else delete condition.campaignId;this.dirty=true;this.updateButtons();return;}
        if(key==='conditionPageUrl'){const condition=node.condition.conditions?node.condition.conditions[Number(input.closest('[data-condition]').dataset.condition)]:node.condition;if(input.value)condition.pageUrl=input.value;else delete condition.pageUrl;
        }else if(['field','operator','value','count'].includes(key)){const condition=node.condition.conditions?node.condition.conditions[Number(input.closest('[data-condition]').dataset.condition)]:node.condition;condition[key]=key==='count'?(input.value===''?null:Number(input.value)):input.value;
            if(key==='field' || key==='operator'){if(key==='operator' && input.value.startsWith('count_'))condition.count ??= 1;else delete condition.count;}
            if(key==='field'){delete condition.pageUrl;delete condition.campaignId;const field=this.workspace.conditionFields.find(f=>f.name===input.value);condition.operator=field?.type==='behavior'?'within_days':'has_value';if(field?.type==='behavior')condition.value='30';else delete condition.value;}
            if(key==='operator'){if(['has_value','is_empty'].includes(input.value))delete condition.value;else {const field=this.workspace.conditionFields.find(f=>f.name===condition.field);condition.value=field?.type==='behavior'?(condition.value || '30'):(field?.options?.[0] ?? '');}}
        }else if(key==='emailSource'){
            const source=(this.workspace.emailSources || []).find(item=>`${item.id}:${item.version}`===input.value);
            node.settings={sourceId:source?.id || '',version:source?.version || 1,parameters:{}};
            this.dirty=true;this.renderEditor();return;
        }else if(key.startsWith('emailBinding:')) {
            const name=key.slice(13);node.settings.parameters ??={};node.settings.parameterBindings ??={};
            if(input.value){node.settings.parameterBindings[name]={field:input.value};delete node.settings.parameters[name];}
            else {delete node.settings.parameterBindings[name];node.settings.parameters[name]='';}
            this.dirty=true;this.renderEditor();return;
        }else if(key.startsWith('emailFallback:')) {
            const binding=node.settings.parameterBindings[key.slice(14)];if(input.value)binding.fallback=input.value;else delete binding.fallback;
        }else if(key.startsWith('emailParameter:'))node.settings.parameters[key.slice(15)]=input.value;
        else if(key.startsWith('opportunity')){
            const property={opportunityName:'name',opportunityPipeline:'pipelineId',opportunityStage:'stage',opportunityAmount:'amount',opportunityCurrency:'currency',opportunityCloseDate:'closeDate'}[key];
            if(property)node.settings[property]=input.value;
            if(key==='opportunityPipeline'){node.settings.stage='';this.dirty=true;this.renderEditor();return;}
        }else if(key==='updateField'){
            node.settings.field=input.value;const scope={'contact.update':'Contact','account.update':'Account','opportunity.update':'Opportunity'}[node.action];node.settings.value=this.workspace.updateProperties?.[scope]?.find(field=>field.name===input.value)?.options?.[0] || '';this.dirty=true;this.renderEditor();return;
        }else if(key==='updateValue')node.settings.value=input.value;
        else if(key==='scoreAmount')node.settings.amount=Number(input.value);
        else if(key==='tagText')node.settings.tag=input.value;
        else if(key==='notificationMessage')node.settings.message=input.value;
        else if(key==='casePriority'){if(input.value)node.settings.priority=input.value;else delete node.settings.priority;}
        else if(key==='caseCategory')node.settings.category=input.value;else if(key==='segmentId')node.settings.segmentId=input.value;else if(key==='matchMode')node.condition.matchMode=input.value;else if(['taskName','caseName'].includes(key))node.settings.name=input.value;else if(key==='stage')node.settings.stage=input.value;else if(key==='weekdays')node.weekdays=Array.from(input.closest('fieldset').querySelectorAll('input:checked'),el=>Number(el.value));
        else node[key]=['seconds','timeoutSeconds','pollSeconds','maxAttempts','retrySeconds'].includes(key)?Number(input.value):input.value;
        this.dirty=true;if(['field','operator'].includes(key))this.renderEditor();else {if(['next','yes','no'].includes(key))this.renderJourney();this.updateButtons();}
    }
    async mutation(callback,success) {
        if(this.busy || !this.current)return;const request=this.sequence;this.busy=true;this.updateButtons();this.message('Saving...');
        try{const result=await callback();if(!this.alive(request))return;success(result);this.message('Saved.');}
        catch(error){if(this.alive(request))this.message(error?.message || 'The change could not be saved. Reload if another administrator changed this workflow.',true);}
        finally{this.busy=false;if(this.alive(request))this.updateButtons();}
    }
    save() {
        if(!this.current?.name.trim()){this.message('Enter a workflow name.',true);this.element.querySelector('[data-name]').focus();return;}
        const c=this.current,payload={name:c.name,definition:structuredClone(c.definition)};if(c.id)payload.expectedRevision=c.draftRevision;
        this.mutation(()=>c.id?Espo.Ajax.putRequest(`Nexa/workflows/${c.id}`,payload):Espo.Ajax.postRequest('Nexa/workflows',payload),result=>{
            if(!c.id){try{const layout=localStorage.getItem(`nexa:workflow-layout:${location.pathname}:draft`);if(layout)localStorage.setItem(`nexa:workflow-layout:${location.pathname}:${result.id}`,layout);}catch(error){}}
            this.accept(result,true);
        });
    }
    accept(result,saved=false) {this.current=result;if(saved)this.dirty=false;const list=this.workspace.list,index=list.findIndex(item=>item.id===result.id);if(index<0)list.unshift(result);else list[index]=result;this.renderList();this.renderEditor();}
    publish() {if(!this.current?.id || this.dirty)return;this.mutation(()=>Espo.Ajax.postRequest(`Nexa/workflows/${this.current.id}/publish`,{expectedRevision:this.current.draftRevision}),result=>this.accept(result));}
    control(event) {
        if(this.dirty){this.message('Save or discard the draft before changing workflow state.',true);return;}const status=event.currentTarget.dataset.control;
        if(status==='archived' && !window.confirm('Archive this workflow and cancel its unfinished enrollments?'))return;
        this.mutation(()=>Espo.Ajax.postRequest(`Nexa/workflows/${this.current.id}/control`,{status}),result=>this.accept(result));
    }
    picker(scope,title,callback) {
        if(this.busy || this.selecting)return;this.selecting=true;const request=this.sequence;
        this.createView('workflowRecordSelector','views/modals/select-records',{scope,multiple:false,createButton:false,headerText:title},view=>{
            if(!this.alive(request)){view.remove();this.selecting=false;return;}
            this.listenToOnce(view,'remove',()=>{this.selecting=false;});this.listenToOnce(view,'select',model=>{this.selecting=false;if(this.alive(request))callback(model);});view.render();
        });
    }
    chooseNativeRecord(event) {
        if(this.busy || this.current?.status==='archived')return;
        const id=event.currentTarget.dataset.nativeRecord,scope=event.currentTarget.dataset.nativeScope;
        this.picker(scope,`Choose ${scope.toLowerCase()}`,model=>{const node=this.current.definition.nodes.find(node=>node.id===id);if(!node)return;node.settings[{Team:'teamId',Opportunity:'opportunityId',Webhook:'webhookId'}[scope]]=model.id;this.ownerNames ??={};this.ownerNames[id]=model.get('name') || model.id;this.dirty=true;this.renderEditor();});
    }
    chooseOwner(event) {
        if(this.current?.status==='archived')return;const id=event.currentTarget.dataset.owner;
        const action=this.current.definition.nodes.find(n=>n.id===id)?.action,title=action==='opportunity.create'?'Choose Opportunity owner':action==='notification.create'?'Choose notification recipient':action==='case.create'?'Choose Case owner':action==='task.create'?'Choose Task owner':'Choose Contact owner';
        this.picker('User',title,model=>{const node=this.current.definition.nodes.find(n=>n.id===id);if(!node)return;node.settings.ownerId=model.id;this.ownerNames[id]=model.get('name') || model.id;this.dirty=true;this.renderEditor();});
    }
    chooseContact() {
        if(this.current?.status!=='published' || this.dirty)return;const c=this.current;
        this.picker('Contact','Enroll Contact',model=>{const identity=`${c.id}:${c.publishedVersion}:${model.id}`;if(this.enrollment?.identity!==identity)this.enrollment={identity,key:this.requestKey()};
            this.mutation(()=>Espo.Ajax.postRequest(`Nexa/workflows/${c.id}/enroll`,{contactId:model.id,requestKey:this.enrollment.key}),()=>{this.enrollment=null;this.history();});});
    }
    async history() {
        if(!this.current?.id)return;const request=this.sequence,id=this.current.id;
        try{const result=await Espo.Ajax.getRequest(`Nexa/workflows/${id}/history`);if(!this.alive(request) || id!==this.current.id)return;
            this.element.querySelector('[data-history]').innerHTML=`<h3>Enrollments</h3><table><thead><tr><th>Contact</th><th>Version</th><th>Status</th><th>Next due (UTC)</th><th></th></tr></thead><tbody>${(result.runs || []).map(run=>`<tr><td><a href="#Contact/view/${encodeURIComponent(run.contactId)}">${this.escape(run.contactId)}</a></td><td>${Number(run.version)}</td><td>${this.escape(this.label(run.status))}</td><td>${this.escape(run.dueAt)}</td><td>${['ready','waiting'].includes(run.status)?`<button type="button" class="btn btn-default" data-cancel-run="${this.escape(run.id)}">Cancel enrollment</button>`:''}</td></tr>`).join('') || '<tr><td colspan="5">No enrollments yet.</td></tr>'}</tbody></table><h3>Audit history</h3><table><thead><tr><th>Event</th><th>Step</th><th>Actor</th><th>Date (UTC)</th></tr></thead><tbody>${(result.events || []).map(e=>`<tr><td>${this.escape(e.eventType)}</td><td>${this.escape(e.nodeId || '-')}</td><td>${this.escape(e.actorUserId || '-')}</td><td>${this.escape(e.createdAt)}</td></tr>`).join('') || '<tr><td colspan="4">No events yet.</td></tr>'}</tbody></table>`;
            const effects=result.webhookEffects || [];
            if(effects.length)this.element.querySelector('[data-history]').insertAdjacentHTML('afterbegin',`<h3>Webhook deliveries</h3><table><thead><tr><th>Step</th><th>Status</th><th>Attempts</th><th>Review</th></tr></thead><tbody>${effects.map(effect=>`<tr><td>${this.escape(effect.nodeId)}</td><td>${this.escape(effect.status)}</td><td>${Number(effect.attempts)}</td><td>${effect.status==='uncertain'?`<select aria-label="Delivery outcome" data-outcome="${this.escape(effect.id)}"><option value="">Choose outcome</option><option value="success">Delivery confirmed</option><option value="failed">Failure confirmed</option></select><textarea aria-label="Delivery evidence" data-evidence="${this.escape(effect.id)}" maxlength="1000"></textarea><button type="button" class="btn btn-default" data-review-webhook="${this.escape(effect.id)}" data-run-id="${this.escape(effect.runId)}">Record review</button>`:'-'}</td></tr>`).join('')}</tbody></table>`);
        }catch(error){if(this.alive(request))this.message('Execution history could not be loaded.',true);}
    }
    reviewWebhook(event) {
        const id=event.currentTarget.dataset.reviewWebhook,run=event.currentTarget.dataset.runId;
        const outcome=this.element.querySelector(`[data-outcome="${CSS.escape(id)}"]`).value,evidence=this.element.querySelector(`[data-evidence="${CSS.escape(id)}"]`).value.trim();
        if(!outcome || evidence.length<10){this.message('Choose an outcome and enter delivery evidence.',true);return;}
        this.mutation(()=>Espo.Ajax.postRequest(`Nexa/workflows/${this.current.id}/runs/${run}/webhook-review`,{outcome,evidence}),()=>this.history());
    }
    cancelRun(event) {const id=event.currentTarget.dataset.cancelRun;if(!window.confirm('Cancel this enrollment?'))return;this.mutation(()=>Espo.Ajax.postRequest(`Nexa/workflows/${this.current.id}/runs/${id}/cancel`,{}),()=>this.history());}
});
