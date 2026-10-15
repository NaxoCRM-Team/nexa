define('custom:views/scoring/workspace',['view','custom:workspace-table'],(Dep,WorkspaceTable)=>class extends Dep {
    template='custom:scoring/workspace';
    events={
        'click [data-action="refresh"]':'load','click [data-action="new"]':'newModel','click [data-model]':'openModel','input [data-search]':'renderModels',
        'click [data-action="close"]':'close','click [data-sort]':'sortModels','change [name="entityType"]':'resetRules','change [data-field]':'changeRule','change [data-operator]':'changeRule',
        'click [data-action="add-rule"]':'addRule','click [data-remove]':'removeRule','submit [data-model-form]':'save',
        'click [data-action="publish"]':'publish','click [data-action="archive"]':'archive','click [data-action="choose-record"]':'chooseRecord',
        'click [data-action="preview"]':'preview','click [data-action="evaluate"]':'evaluate',
        'click [data-action="add-band"]':'addBand','click [data-remove-band]':'removeBand',
        'input [data-model-form]':'markDirty','change [data-model-form]':'markDirty',
        'click [data-action="schedule"]':'schedule','change [data-decay-enabled]':'changeDecay',
        'click [data-action="primary"]':'primary',
        'submit [data-adjustment-form]':'adjust','click [data-revoke]':'revoke','click [data-action="cancel-revoke"]':'resetAdjustment',
        'change [data-lifecycle-enabled]':'toggleLifecycle','input [data-lifecycle-rules]':'markDirty',
        'input [data-adjustment-form]':'adjustmentChanged',
        'click [data-action="add-group"]':'addGroup','click [data-add-condition]':'addCondition','click [data-add-condition-group]':'addConditionGroup','click [data-remove-condition]':'removeCondition',
    };
    setup(){this.setPageTitle('Scoring & Lifecycle');this.workspace={models:[],fields:{}};this.tables=new WorkspaceTable(this);this.sequence=0;}
    afterRender(){super.afterRender();if(!document.getElementById('nexa-scoring-style')){const link=document.createElement('link');link.id='nexa-scoring-style';link.rel='stylesheet';link.href='client/custom/css/scoring-workspace.css';document.head.append(link);}this.load();this.once('remove',()=>{this.sequence++;});}
    escape(value){return String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
    label(value){return String(value).replace(/([A-Z])/g,' $1').replaceAll('_',' ').replace(/^./,c=>c.toUpperCase()).trim();}
    randomKey(){return [...crypto.getRandomValues(new Uint8Array(16))].map(value=>value.toString(16).padStart(2,'0')).join('');}
    error(message=''){const el=this.element.querySelector('[data-error]');el.textContent=message;el.hidden=!message;}
    async load(){const sequence=++this.sequence;this.element.querySelector('[data-loading]').hidden=false;this.error();try{const result=await Espo.Ajax.getRequest('Nexa/scoring/workspace');if(sequence!==this.sequence)return;this.workspace=result;this.element.querySelector('[data-action="new"]').disabled=!result.canManage||!Object.keys(result.fields).length;this.renderModels();this.element.querySelector('[data-action="new"]').hidden=!result.canManage;this.element.querySelector('[data-quota]').textContent=`Active models ${result.models.filter(m=>m.status==='published').length} / ${result.publishedLimit}`;}catch(e){this.error(e?.message||'Scoring models could not be loaded.');}finally{if(sequence===this.sequence)this.element.querySelector('[data-loading]').hidden=true;}}
    renderModels(){const search=this.element.querySelector('[data-search]').value.toLowerCase();const models=this.workspace.models.filter(m=>`${m.name} ${m.entityType} ${m.status}`.toLowerCase().includes(search));this.element.querySelector('[data-total]').textContent=`Total ${models.length}`;this.element.querySelector('[data-models]').innerHTML=models.map(m=>`<tr><td><button class="ns-name" data-model="${this.escape(m.id)}">${this.escape(m.name)}</button></td><td>${this.escape(m.entityType)}</td><td>${this.escape(this.label(m.status))}</td><td>${Number(m.version)}</td></tr>`).join('')||'<tr><td colspan="4">No scoring models</td></tr>';this.tables.enhance(this.element.querySelector('[data-model-table]'),`scoring:${location.pathname}`);}
    sortModels(event){const key=event.currentTarget.dataset.sort;this.sortDirection=this.sortKey===key?-this.sortDirection:1;this.sortKey=key;this.workspace.models.sort((a,b)=>(key==='version'?Number(a[key])-Number(b[key]):String(a[key]).localeCompare(String(b[key])))*this.sortDirection);this.renderModels();}
    newModel(){this.edit(null);}
    openModel(event){this.edit(this.workspace.models.find(m=>m.id===event.currentTarget.dataset.model));}
    edit(model){
        this.sequence++;this.current=model||null;this.dirty=false;this.record=null;this.requestKey=null;
        this.element.querySelector('[data-result]').textContent='';this.element.querySelector('[data-history]').textContent='';this.element.querySelector('[data-record-name]').textContent='';
        const form=this.element.querySelector('[data-model-form]');form.reset();form.elements.name.value=model?.name||'';
        form.elements.entityType.innerHTML=Object.keys(this.workspace.fields).map(t=>`<option>${this.escape(t)}</option>`).join('');if(model)form.elements.entityType.value=model.entityType;
        const locked=!!model&&model.status!=='draft';form.elements.name.disabled=locked||!this.workspace.canManage;form.elements.entityType.disabled=!!model||!this.workspace.canManage;
        if(!form.querySelector('[data-action="add-group"]')){const button=document.createElement('button');button.type='button';button.className='btn btn-default';button.dataset.action='add-group';button.innerHTML='<span class="fas fa-layer-group" aria-hidden="true"></span> Add condition group';form.querySelector('footer').prepend(button);}
        form.querySelector('[data-action="add-group"]').hidden=locked||!this.workspace.canManage;
        this.element.querySelector('[data-rules]').innerHTML='';(model?.definition.rules||[{field:Object.keys(this.workspace.fields[form.elements.entityType.value]||{})[0],operator:'has_value',value:'',points:10}]).forEach(rule=>this.addRule(null,rule));
        this.element.querySelector('[data-save]').hidden=locked||!this.workspace.canManage;this.element.querySelector('[data-action="add-rule"]').hidden=locked||!this.workspace.canManage;
        this.element.querySelector('[data-action="publish"]').hidden=!model||locked||!this.workspace.canManage;this.element.querySelector('[data-action="archive"]').hidden=!model||model.status==='archived'||!this.workspace.canManage;
        this.renderBands(model?.definition.thresholds,locked||!this.workspace.canManage);
        this.renderLifecycle(model?.definition.lifecycle,locked||!this.workspace.canManage);
        this.resetAdjustment();this.element.querySelector('[data-adjustment-editor]').hidden=true;
        this.element.querySelector('[data-schedule]').hidden=model?.status!=='published'||!this.workspace.canManage;
        this.element.querySelector('[data-primary]').hidden=model?.status!=='published'||!this.workspace.canManage||!['Contact','Account'].includes(model?.entityType);
        this.element.querySelector('[data-primary-enabled]').checked=!!Number(model?.isPrimary);
        this.element.querySelector('[data-automatic]').checked=!!Number(model?.automaticEnabled);this.element.querySelector('[data-interval]').value=String(model?.intervalHours||24);
        this.element.querySelector('[data-schedule-status]').textContent=model?.lastErrorCode?`Recalculation needs attention: ${this.label(model.lastErrorCode)}`:(model?.lastCompletedAt?`Last completed: ${model.lastCompletedAt}`:'');
        this.element.querySelector('[data-preview]').hidden=!model;this.element.querySelector('[data-action="evaluate"]').hidden=!this.workspace.canManage||model?.status!=='published';
        this.element.querySelectorAll('[data-action="preview"],[data-action="evaluate"]').forEach(b=>b.disabled=true);this.element.querySelector('[data-title]').textContent=model?model.name:'New scoring model';this.element.querySelector('[data-editor]').hidden=false;this.error();if(!locked)form.elements.name.focus();
    }
    close(){this.sequence++;this.current=null;this.element.querySelector('[data-editor]').hidden=true;}
    resetRules(){this.element.querySelector('[data-rules]').innerHTML='';this.addRule();this.renderLifecycle(null,false);}
    renderLifecycle(policy,locked){
        const available=this.workspace.fields[this.element.querySelector('[name="entityType"]').value]?.lifecycleStage?.options||[];
        this.lifecycleLocked=locked;this.element.querySelector('[data-lifecycle-editor]').hidden=!available.length;
        const toggle=this.element.querySelector('[data-lifecycle-enabled]');toggle.checked=!!policy;toggle.disabled=locked;
        const thresholds=policy?.thresholds||[{min:0,stage:'Lead'},{min:51,stage:'MarketingQualifiedLead'},{min:81,stage:'SalesQualifiedLead'}];
        this.element.querySelector('[data-lifecycle-rules]').innerHTML=thresholds.map((row,index)=>`<label>${this.escape(this.label(row.stage))}<input class="form-control" type="number" min="0" max="1000000" step="1" data-lifecycle-min data-stage="${this.escape(row.stage)}" value="${Number(row.min)}" ${index===0?'readonly':''}></label>`).join('');
        this.configureLifecycle();
    }
    configureLifecycle(){const enabled=this.element.querySelector('[data-lifecycle-enabled]').checked;this.element.querySelectorAll('[data-lifecycle-min]').forEach(input=>{input.disabled=!enabled||this.lifecycleLocked;input.required=enabled;});}
    toggleLifecycle(){this.configureLifecycle();this.markDirty();}
    resetAdjustment(){this.revokeId=null;this.adjustmentKey=null;const form=this.element.querySelector('[data-adjustment-form]');form.reset();form.elements.delta.disabled=false;form.elements.expiresAt.disabled=false;this.element.querySelector('[data-revoke-status]').textContent='';this.element.querySelector('[data-action="cancel-revoke"]').hidden=true;}
    adjustmentChanged(){this.adjustmentKey=null;}
    revoke(event){this.resetAdjustment();this.revokeId=event.currentTarget.dataset.revoke;const form=this.element.querySelector('[data-adjustment-form]');form.elements.delta.disabled=true;form.elements.expiresAt.disabled=true;this.element.querySelector('[data-revoke-status]').textContent='Revoke the selected adjustment';this.element.querySelector('[data-action="cancel-revoke"]').hidden=false;form.elements.reason.focus();}
    async adjust(event){
        event.preventDefault();const form=event.currentTarget;if(!this.current||!this.record||this.adjusting||!form.reportValidity())return;
        const sequence=this.sequence;this.adjustmentKey=this.adjustmentKey||this.randomKey();
        const payload={recordId:this.record.id,requestKey:this.adjustmentKey,reason:form.elements.reason.value};
        if(this.revokeId)payload.revokeId=this.revokeId;else{payload.delta=Number(form.elements.delta.value);payload.expiresAt=form.elements.expiresAt.value?form.elements.expiresAt.value.replace('T',' ')+':00':null;}
        this.adjusting=true;this.element.querySelector('[data-adjustment-submit]').disabled=true;
        try{const response=await Espo.Ajax.postRequest(`Nexa/scoring/${this.current.id}/adjustments`,payload);if(sequence!==this.sequence)return;this.error();this.showResult(response.result);this.showCategories(response.result);this.resetAdjustment();await this.history();}
        catch(error){if(sequence===this.sequence)this.error(error?.message||'The adjustment could not be recorded.');}
        finally{this.adjusting=false;this.element.querySelector('[data-adjustment-submit]').disabled=false;}
    }
    renderBands(bands,locked){this.element.querySelector('[data-bands]').innerHTML='';(bands||[{min:0,label:'Cold'},{min:21,label:'Engaged'},{min:51,label:'Marketing qualified'},{min:81,label:'Sales ready'}]).forEach(b=>this.addBand(null,b));this.element.querySelector('[data-action="add-band"]').hidden=locked;if(locked)this.element.querySelectorAll('[data-band] input,[data-remove-band]').forEach(el=>el.disabled=true);}
    addBand(event,band){const holder=this.element.querySelector('[data-bands]');if(holder.children.length>=10)return;const index=holder.children.length;if(!band){const previous=holder.lastElementChild;band={min:previous?Number(previous.querySelector('[data-band-min]').value)+1:0,label:''};}const row=document.createElement('div');row.dataset.band='';row.className='ns-band';row.innerHTML=`<label>Minimum score<input class="form-control" type="number" min="0" max="1000000" step="1" required data-band-min value="${Number(band.min)}" ${index===0?'readonly':''}></label><label>Band label<input class="form-control" maxlength="60" required data-band-label value="${this.escape(band.label)}"></label><button class="btn btn-default" type="button" data-remove-band aria-label="Remove score band" title="Remove score band" ${index===0?'hidden':''}><span class="fas fa-times" aria-hidden="true"></span></button>`;holder.append(row);}
    removeBand(event){event.currentTarget.closest('[data-band]').remove();}
    addRule(event,rule={}){
        if(rule.conditions)return this.addGroup(null,rule);
        const type=this.element.querySelector('[name="entityType"]').value;const fields=this.workspace.fields[type]||{};
        const row=document.createElement('div');row.className='ns-rule';row.dataset.rule=rule.id||this.randomKey();
        row.innerHTML=`<label>Property<select class="form-control" data-field>${Object.keys(fields).map(f=>`<option value="${this.escape(f)}">${this.escape(fields[f].label||this.label(f))}</option>`).join('')}</select></label><label>Condition<select class="form-control" data-operator></select></label><label>Value<span data-value-holder></span></label><label>Points<input class="form-control" type="number" min="-10000" max="10000" step="1" value="${Number(rule.points??10)}" data-points required></label><button class="btn btn-default" type="button" data-remove title="Remove rule" aria-label="Remove rule"><span class="fas fa-times" aria-hidden="true"></span></button><fieldset data-decay hidden><legend>Inactivity decay</legend><label><input type="checkbox" data-decay-enabled> Reduce behaviour points</label><div class="ns-fields" data-decay-settings hidden><label>Start after days<input class="form-control" type="number" min="0" max="3650" step="1" data-decay-after value="${Number(rule.decay?.afterDays??7)}"></label><label>Repeat every days<input class="form-control" type="number" min="1" max="365" step="1" data-decay-every value="${Number(rule.decay?.everyDays??7)}"></label><label>Points to remove<input class="form-control" type="number" min="1" max="10000" step="1" data-decay-points value="${Number(rule.decay?.points??5)}"></label></div></fieldset>`;
        this.element.querySelector('[data-rules]').append(row);if(rule.field)row.querySelector('[data-field]').value=rule.field;
        const category=document.createElement('label');category.className='ns-category';category.innerHTML='<span>Score category</span><select class="form-control" data-category><option value="fit">Fit</option><option value="engagement">Engagement</option></select>';
        row.insertBefore(category,row.querySelector('[data-decay]'));category.querySelector('select').value=rule.category||(fields[row.querySelector('[data-field]').value]?.event?'engagement':'fit');
        this.configureRule(row,rule.operator,rule.value);row.querySelector('[data-decay-enabled]').checked=!!rule.decay;this.configureDecay(row);
        this.configureCurrency(row,rule.currency);
        if(this.current?.status!=='draft'&&this.current||!this.workspace.canManage)row.querySelectorAll('input,select,button').forEach(c=>c.disabled=true);
    }
    configureDecay(row){
        const field=this.workspace.fields[this.element.querySelector('[name="entityType"]').value]?.[row.querySelector('[data-field]').value];
        const available=!!field?.event;row.querySelector('[data-decay]').hidden=!available;
        const checkbox=row.querySelector('[data-decay-enabled]');if(!available)checkbox.checked=false;
        const enabled=available&&checkbox.checked;row.querySelector('[data-decay-settings]').hidden=!enabled;
        row.querySelectorAll('[data-decay-settings] input').forEach(input=>{input.disabled=!enabled;input.required=enabled;});
    }
    changeDecay(event){this.configureDecay(event.currentTarget.closest('[data-rule]'));}
    configureRule(row,operator,value){const field=this.workspace.fields[this.element.querySelector('[name="entityType"]').value]?.[row.querySelector('[data-field]').value];const numeric=['int','float','currency','decimal'].includes(field?.type);const behavior=!!field?.event;const operators=[...(behavior?[]:['has_value']),'equals','not_equals',...(numeric?['greater_than','less_than']:[])];row.querySelector('[data-operator]').innerHTML=operators.map(o=>`<option value="${o}">${this.label(o)}</option>`).join('');if(operators.includes(operator))row.querySelector('[data-operator]').value=operator;else if(behavior)row.querySelector('[data-operator]').value='greater_than';const has=row.querySelector('[data-operator]').value==='has_value';row.querySelector('[data-value-holder]').innerHTML=field?.type==='enum'?`<select class="form-control" data-value>${(field.options||[]).map(o=>`<option value="${this.escape(o)}">${this.escape(o)}</option>`).join('')}</select>`:'<input class="form-control" data-value maxlength="255">';const input=row.querySelector('[data-value]');if(value!==undefined)input.value=value;else if(behavior)input.value='0';input.disabled=has;input.required=!has;}
    changeRule(event){const row=event.currentTarget.closest('[data-condition-leaf],[data-rule]');const operator=event.currentTarget.matches('[data-operator]')?event.currentTarget.value:undefined;this.configureRule(row,operator,event.currentTarget.matches('[data-field]')?undefined:row.querySelector('[data-value]')?.value);this.configureCurrency(row);if(row.querySelector('[data-decay]'))this.configureDecay(row);}
    configureCurrency(row,selected){
        const field=this.workspace.fields[this.element.querySelector('[name="entityType"]').value]?.[row.querySelector('[data-field]').value];
        const previous=selected||row.querySelector('[data-currency]')?.value;row.querySelector('[data-currency-holder]')?.remove();if(field?.type!=='currency')return;
        const label=document.createElement('label');label.dataset.currencyHolder='';label.innerHTML=`Comparison currency<select class="form-control" data-currency>${(field.currencies||[]).map(code=>`<option>${this.escape(code)}</option>`).join('')}</select>`;row.append(label);
        label.querySelector('select').value=(field.currencies||[]).includes(previous)?previous:(field.defaultCurrency||field.currencies?.[0]||'');
    }
    addGroup(event,rule={}){
        const row=document.createElement('fieldset');row.className='ns-rule-group';row.dataset.rule=rule.id||this.randomKey();row.dataset.group='';
        row.innerHTML=`<legend>Condition group</legend><div class="ns-actions"><label>Points<input class="form-control" type="number" min="-10000" max="10000" step="1" data-points value="${Number(rule.points??10)}" required></label><label>Score category<select class="form-control" data-category><option value="fit">Fit</option><option value="engagement">Engagement</option></select></label><button class="btn btn-default" type="button" data-remove aria-label="Remove group" title="Remove group"><span class="fas fa-times" aria-hidden="true"></span></button></div>`;
        this.element.querySelector('[data-rules]').append(row);row.querySelector('[data-category]').value=rule.category||'fit';
        row.append(this.conditionNode(rule.conditions||{operator:'and',conditions:[{field:Object.keys(this.workspace.fields[this.element.querySelector('[name="entityType"]').value]||{})[0],operator:'has_value'}]},0));
        if((this.current&&this.current.status!=='draft')||!this.workspace.canManage)row.querySelectorAll('input,select,button').forEach(input=>input.disabled=true);
        if(event)this.markDirty();
    }
    conditionNode(condition,depth){
        const node=document.createElement('div');
        if(condition.conditions){
            node.dataset.conditionGroup='';node.dataset.depth=String(depth);node.className='ns-condition-group';
            node.innerHTML=`<div class="ns-actions"><label>Match<select class="form-control" data-group-operator><option value="and">All conditions (AND)</option><option value="or">Any condition (OR)</option></select></label><button class="btn btn-default" type="button" data-add-condition><span class="fas fa-plus" aria-hidden="true"></span> Add condition</button>${depth<3?'<button class="btn btn-default" type="button" data-add-condition-group><span class="fas fa-layer-group" aria-hidden="true"></span> Add subgroup</button>':''}${depth>0?'<button class="btn btn-default" type="button" data-remove-condition aria-label="Remove subgroup" title="Remove subgroup"><span class="fas fa-times" aria-hidden="true"></span></button>':''}</div><div data-condition-children></div>`;
            node.querySelector('[data-group-operator]').value=condition.operator;
            condition.conditions.forEach(child=>node.querySelector('[data-condition-children]').append(this.conditionNode(child,depth+1)));
        }else{
            node.dataset.conditionLeaf='';node.className='ns-condition-leaf';const fields=this.workspace.fields[this.element.querySelector('[name="entityType"]').value]||{};
            node.innerHTML=`<label>Property<select class="form-control" data-field>${Object.keys(fields).map(key=>`<option value="${this.escape(key)}">${this.escape(fields[key].label||this.label(key))}</option>`).join('')}</select></label><label>Condition<select class="form-control" data-operator></select></label><label>Value<span data-value-holder></span></label><button class="btn btn-default" type="button" data-remove-condition aria-label="Remove condition" title="Remove condition"><span class="fas fa-times" aria-hidden="true"></span></button>`;
            node.querySelector('[data-field]').value=condition.field;this.configureRule(node,condition.operator,condition.value);this.configureCurrency(node,condition.currency);
        }
        return node;
    }
    addCondition(event){const group=event.currentTarget.closest('[data-condition-group]');const children=group.querySelector(':scope > [data-condition-children]');if(children.children.length>=10){this.error('A group supports at most ten conditions.');return;}children.append(this.conditionNode({field:Object.keys(this.workspace.fields[this.element.querySelector('[name="entityType"]').value]||{})[0],operator:'has_value'},Number(group.dataset.depth)+1));this.markDirty();}
    addConditionGroup(event){const group=event.currentTarget.closest('[data-condition-group]');const children=group.querySelector(':scope > [data-condition-children]');if(Number(group.dataset.depth)>=3||children.children.length>=10){this.error('The group limit has been reached.');return;}children.append(this.conditionNode({operator:'or',conditions:[{field:Object.keys(this.workspace.fields[this.element.querySelector('[name="entityType"]').value]||{})[0],operator:'has_value'}]},Number(group.dataset.depth)+1));this.markDirty();}
    removeCondition(event){event.currentTarget.closest('[data-condition-leaf],[data-condition-group]').remove();this.markDirty();}
    readCondition(node){
        if(node.matches('[data-condition-group]'))return{operator:node.querySelector(':scope > .ns-actions [data-group-operator]').value,conditions:[...node.querySelector(':scope > [data-condition-children]').children].map(child=>this.readCondition(child))};
        const condition={field:node.querySelector('[data-field]').value,operator:node.querySelector('[data-operator]').value,value:node.querySelector('[data-value]').value};if(node.querySelector('[data-currency]'))condition.currency=node.querySelector('[data-currency]').value;return condition;
    }
    removeRule(event){event.currentTarget.closest('[data-rule]').remove();this.markDirty();}
    async save(event){
        event.preventDefault();const form=event.currentTarget;if(!form.reportValidity())return;
        const rules=[...form.querySelectorAll('[data-rule]')].map(row=>{
            if(row.matches('[data-group]'))return{id:row.dataset.rule,points:Number(row.querySelector('[data-points]').value),category:row.querySelector('[data-category]').value,conditions:this.readCondition(row.querySelector('[data-condition-group]'))};
            const rule={id:row.dataset.rule,field:row.querySelector('[data-field]').value,operator:row.querySelector('[data-operator]').value,value:row.querySelector('[data-value]').value,points:Number(row.querySelector('[data-points]').value)};
            rule.category=row.querySelector('[data-category]').value;
            if(row.querySelector('[data-currency]'))rule.currency=row.querySelector('[data-currency]').value;
            if(row.querySelector('[data-decay-enabled]').checked)rule.decay={afterDays:Number(row.querySelector('[data-decay-after]').value),everyDays:Number(row.querySelector('[data-decay-every]').value),points:Number(row.querySelector('[data-decay-points]').value)};
            return rule;
        });
        const payload={name:form.elements.name.value,entityType:form.elements.entityType.value,definition:{rules,thresholds:[...form.querySelectorAll('[data-band]')].map(row=>({min:Number(row.querySelector('[data-band-min]').value),label:row.querySelector('[data-band-label]').value}))}};
        if(this.element.querySelector('[data-lifecycle-enabled]').checked){
            const inputs=[...this.element.querySelectorAll('[data-lifecycle-min]')];if(inputs.some(input=>!input.reportValidity()))return;
            payload.definition.lifecycle={thresholds:inputs.map(input=>({min:Number(input.value),stage:input.dataset.stage}))};
        }
        if(this.current)payload.id=this.current.id;await this.mutate(()=>Espo.Ajax.postRequest('Nexa/scoring/models',payload));
    }
    schedule(){if(this.current)this.mutate(()=>Espo.Ajax.postRequest(`Nexa/scoring/${this.current.id}/control`,{action:'schedule',enabled:this.element.querySelector('[data-automatic]').checked,intervalHours:Number(this.element.querySelector('[data-interval]').value)}));}
    primary(){if(this.current)this.mutate(()=>Espo.Ajax.postRequest(`Nexa/scoring/${this.current.id}/control`,{action:'primary',enabled:this.element.querySelector('[data-primary-enabled]').checked}));}
    async mutate(fn){const sequence=this.sequence;const buttons='[data-save],[data-action="publish"],[data-action="archive"],[data-action="schedule"],[data-action="primary"]';this.element.querySelectorAll(buttons).forEach(b=>b.disabled=true);try{const result=await fn();if(sequence!==this.sequence)return;await this.load();this.edit(this.workspace.models.find(m=>m.id===result.id));}catch(e){if(sequence===this.sequence)this.error(e?.message||'The model could not be updated.');}finally{this.element.querySelectorAll(buttons).forEach(b=>b.disabled=false);}}
    markDirty(){this.dirty=true;}
    publish(){if(this.dirty){this.error('Save the draft before publishing.');return;}if(this.current)this.mutate(()=>Espo.Ajax.postRequest(`Nexa/scoring/${this.current.id}/control`,{action:'publish'}));}
    archive(){if(this.current&&window.confirm('Archive this model? Existing evaluation evidence will be retained.'))this.mutate(()=>Espo.Ajax.postRequest(`Nexa/scoring/${this.current.id}/control`,{action:'archive'}));}
    chooseRecord(){if(!this.current)return;const sequence=this.sequence;this.createView('scoringRecord','views/modals/select-records',{scope:this.current.entityType,multiple:false,createButton:false},view=>{view.render();this.listenToOnce(view,'select',record=>{if(sequence!==this.sequence)return;this.sequence++;this.record=record;this.requestKey=null;this.element.querySelector('[data-record-name]').textContent=record.get('name')||record.id;this.element.querySelector('[data-result]').textContent='';this.element.querySelectorAll('[data-action="preview"],[data-action="evaluate"]').forEach(b=>b.disabled=false);this.history();});});}
    preview(){this.runEvaluation(false);}
    evaluate(){this.runEvaluation(true);}
    async runEvaluation(persist){
        if(!this.current||!this.record||this.evaluating)return;if(this.dirty){this.error('Save the draft before evaluating.');return;}
        const sequence=this.sequence;const payload={recordId:this.record.id};if(persist){this.requestKey=this.requestKey||this.randomKey();payload.requestKey=this.requestKey;}
        this.evaluating=true;this.element.querySelectorAll('[data-action="preview"],[data-action="evaluate"]').forEach(b=>b.disabled=true);
        try{
            const result=await Espo.Ajax.postRequest(`Nexa/scoring/${this.current.id}/${persist?'evaluate':'preview'}`,payload);if(sequence!==this.sequence)return;this.error();
            this.showResult(result);
            this.showCategories(result);
            if(persist){this.requestKey=null;await this.history();}
        }catch(e){if(sequence===this.sequence)this.error(e?.message||'The score could not be calculated.');}
        finally{this.evaluating=false;if(sequence===this.sequence)this.element.querySelectorAll('[data-action="preview"],[data-action="evaluate"]').forEach(b=>b.disabled=false);}
    }
    conditionExplanation(condition){
        if(condition.conditions)return `<details open><summary>${condition.operator==='and'?'All conditions':'Any condition'}: ${condition.matched?'Matched':'Not matched'}</summary><div class="ns-explanation">${condition.conditions.map(child=>this.conditionExplanation(child)).join('')}</div></details>`;
        const fields=this.workspace.fields[this.current.entityType]||{};
        return `<p>${this.escape(fields[condition.field]?.label||this.label(condition.field))}: ${this.escape(condition.actual??'Not recorded')}${condition.currency?` ${this.escape(condition.currency)}`:''} / ${this.escape(this.label(condition.operator))}${condition.expected!==null&&condition.expected!==undefined?` ${this.escape(condition.expected)}`:''} <small>(${condition.matched?'Matched':'Not matched'})</small></p>`;
    }
    showResult(result){
        const fields=this.workspace.fields[this.current.entityType]||{};
        this.element.querySelector('[data-result]').innerHTML=`<p class="ns-score">${Number(result.score)} points <small>${this.escape(result.band||'')}</small></p>${result.adjustmentTotal?`<p>Model: ${Number(result.modelScore)} / Adjustment: ${Number(result.adjustmentTotal)}</p>`:''}${result.lifecycle?.eligible?`<p>Lifecycle: ${this.escape(this.label(result.lifecycle.target))} (${result.lifecycle.applied?'Applied':'Preview'})</p>`:''}${result.contributions.map(c=>`<div class="ns-reason"><div>${c.conditions?this.conditionExplanation(c):`<span>${this.escape(fields[c.field]?.label||this.label(c.field))}: ${this.escape(c.actual??'Not recorded')}${c.currency?` ${this.escape(c.currency)}`:''} <small>(${c.matched?'Matched':'Not matched'}${c.decayedPoints?`; ${Number(c.decayedPoints)} points removed for inactivity`:''})</small></span>`}</div><strong class="${c.points<0?'ns-negative':'ns-positive'}">${c.points>0?'+':''}${Number(c.points)}</strong></div>`).join('')}`;
        const currencyInputs=Object.entries(result.inputs||{}).filter(([,value])=>value&&typeof value==='object'&&value.normalized);
        if(currencyInputs.length)this.element.querySelector('[data-result]').insertAdjacentHTML('beforeend',`<details><summary>Currency evidence</summary>${currencyInputs.map(([field,value])=>`<p>${this.escape(fields[field]?.label||this.label(field))}: ${this.escape(value.amount)} ${this.escape(value.code)}${Object.entries(value.normalized).map(([code,amount])=>` / ${this.escape(amount)} ${this.escape(code)}`).join('')}<br><small>Rate date: ${this.escape(value.effectiveDate||'Not recorded')} / ${this.escape(value.rateMode)}</small></p>`).join('')}</details>`);
    }
    showCategories(result){if(!result.categoryScores)return;const summary=document.createElement('div');summary.className='ns-category-summary';summary.innerHTML=`<span>Fit <strong>${Number(result.categoryScores.fit)}</strong></span><span>Engagement <strong>${Number(result.categoryScores.engagement)}</strong></span>`;this.element.querySelector('[data-result]').prepend(summary);}
    async history(){if(!this.record)return;const sequence=this.sequence;try{const result=await Espo.Ajax.getRequest(`Nexa/scoring/${this.current.id}/evaluations/${this.record.id}`);if(sequence!==this.sequence)return;this.element.querySelector('[data-history]').innerHTML=result.evaluations.map(e=>`<div class="ns-reason"><span>Version ${Number(e.version)} / ${this.escape(e.evaluatedAt)}${e.categoryScores?` / Fit ${Number(e.categoryScores.fit)} / Engagement ${Number(e.categoryScores.engagement)}`:''}</span><strong>${Number(e.score)} points</strong></div>`).join('')||'<p>No recorded evaluations</p>';
        if(result.hasMoreEvaluations){const status=document.createElement('p');status.className='text-muted';status.textContent='Latest 20 evaluations';this.element.querySelector('[data-history]').prepend(status);}
        this.element.querySelector('[data-adjustment-editor]').hidden=!this.workspace.canManage||!Number(this.current.isPrimary)||this.current.status!=='published';
        this.element.querySelector('[data-adjustments]').innerHTML=(result.adjustments||[]).map(a=>`<div class="ns-reason"><span><strong>${Number(a.delta)>0?'+':''}${Number(a.delta)} / ${this.escape(this.label(a.status))}</strong><br>${this.escape(a.reason)}<br><small>${this.escape(a.createdAt)}${a.expiresAt?` / Expires ${this.escape(a.expiresAt)} UTC`:''}</small></span>${a.status==='active'?`<button class="btn btn-default" data-revoke="${this.escape(a.id)}" title="Revoke adjustment" aria-label="Revoke adjustment"><span class="fas fa-undo" aria-hidden="true"></span></button>`:''}</div>`).join('')||'<p>No manual adjustments</p>';
        if(result.hasMoreAdjustments){const status=document.createElement('p');status.className='text-muted';status.textContent='Latest 50 adjustments';this.element.querySelector('[data-adjustments]').prepend(status);}
    }catch(e){if(sequence===this.sequence)this.error(e?.message||'Evaluation history could not be loaded.');}}
});
