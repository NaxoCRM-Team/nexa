define('custom:views/tracking/workspace', ['view'], Dep => class extends Dep {
    template = 'custom:tracking/workspace';
    events = {
        'click [data-action="reload"]': 'load',
        'click [data-action="add-source"]': 'openCreate',
        'click [data-action="edit-source"]': 'openEdit',
        'click [data-action="close-source"]': 'closeEditor',
        'submit [data-source-form]': 'save',
        'click [data-action="copy-embed"]': 'copyEmbed',
        'click [data-action="test-source"]': 'testSource',
        'click [data-action="rotate-key"]': 'rotateKey',
        'submit [data-retention-form]': 'saveRetention',
        'change [name="legalHold"]': 'toggleRetentionHold',
        'click [data-action="purge-retention"]': 'purgeRetention',
    };
    setup() { this.setPageTitle('Tracking & Events'); this.workspace = null; this.current = null; }
    afterRender() { this.element?.closest('#main')?.classList.add('nexa-tracking-page'); this.load(); }
    remove() { this.element?.closest('#main')?.classList.remove('nexa-tracking-page'); super.remove(); }
    state(name) { ['loading','error','ready'].forEach(value => { const node=this.element.querySelector(`[data-tracking-state="${value}"]`); if(node)node.hidden=value!==name; }); }
    async load() { this.state('loading'); try { this.workspace=await Espo.Ajax.getRequest('Nexa/tracking/sources'); this.renderSources(); this.state('ready'); } catch(error) { this.state('error'); Espo.Ui.error(error?.message||'Tracking sources could not be loaded.'); } }
    renderSources() {
        const list=this.workspace?.sources||[]; this.element.querySelector('[data-source-total]').textContent=`${list.length} ${list.length===1?'source':'sources'}`;
        this.element.querySelector('[data-source-list]').innerHTML=list.length?list.map(item=>`<article class="nexa-tracking-source ${item.status==='paused'?'is-paused':''}"><header><span class="fas fa-wave-square"></span><div><h2>${this.escape(item.name)}</h2><p>${item.allowedOrigins.map(this.escape).join(' · ')}</p></div><span class="nexa-tracking-status">${this.escape(item.status)}</span></header><div class="nexa-tracking-metrics"><span><b>${Number(item.eventCount||0).toLocaleString()}</b>Events received</span><span><b>${this.escape(item.integrationMode.replaceAll('_',' '))}</b>Consent mode</span><span><b>${this.escape(this.date(item.lastEventAt))}</b>Last event</span></div><footer><button class="btn btn-default" type="button" data-action="test-source" data-id="${item.id}" ${item.status!=='active'?'disabled':''}><span class="fas fa-vial"></span>Send test event</button><button class="btn btn-default" type="button" data-action="copy-embed" data-id="${item.id}"><span class="fas fa-copy"></span>Copy embed code</button><button class="btn btn-default" type="button" data-action="edit-source" data-id="${item.id}"><span class="fas fa-pen"></span>Edit</button></footer></article>`).join(''):'<section class="nexa-tracking-empty"><span class="fas fa-satellite-dish"></span><h2>No tracking source yet</h2><p>Add the first approved website before installing the Nexa tracking script.</p><button class="btn btn-primary" type="button" data-action="add-source"><span class="fas fa-plus"></span>Add website</button></section>';
        this.renderRetention();
    }
    renderRetention() {
        const retention=this.workspace?.retention||{}; const policy=retention.policy||{}; const storage=retention.storage||{}; const form=this.element.querySelector('[data-retention-form]'); if(!form)return;
        form.elements.identifiedDays.value=policy.identifiedDays??730; form.elements.anonymousDays.value=policy.anonymousDays??90; form.elements.replayDays.value=policy.replayDays??90; form.elements.legalHold.checked=policy.legalHold===true; form.elements.legalHoldReason.value=policy.legalHoldReason||'';
        this.element.querySelector('[data-retention-total]').textContent=Number(storage.totalEvents||0).toLocaleString(); this.element.querySelector('[data-retention-identified]').textContent=Number(storage.identifiedEvents||0).toLocaleString(); this.element.querySelector('[data-retention-anonymous]').textContent=Number(storage.anonymousEvents||0).toLocaleString(); this.element.querySelector('[data-retention-eligible]').textContent=Number(storage.eligibleEvents||0).toLocaleString(); this.element.querySelector('[data-retention-oldest]').textContent=this.date(storage.oldestEventAt);
        const hold=this.element.querySelector('[data-retention-hold-status]'); hold.hidden=policy.legalHold!==true; const updated=this.element.querySelector('[data-retention-updated]'); updated.textContent=policy.updatedAt?`Updated ${this.date(policy.updatedAt)}`:'Default policy';
        this.toggleRetentionHold(); const purge=this.element.querySelector('[data-action="purge-retention"]'); purge.disabled=policy.legalHold===true||Number(storage.eligibleEvents||0)===0;
    }
    toggleRetentionHold() { const form=this.element.querySelector('[data-retention-form]'); if(!form)return; const active=form.elements.legalHold.checked; const row=this.element.querySelector('[data-retention-reason]'); row.hidden=!active; form.elements.legalHoldReason.required=active; }
    async saveRetention(event) {
        event.preventDefault(); const form=event.currentTarget; const payload={identifiedDays:Number(form.elements.identifiedDays.value),anonymousDays:Number(form.elements.anonymousDays.value),replayDays:Number(form.elements.replayDays.value),legalHold:form.elements.legalHold.checked,legalHoldReason:form.elements.legalHoldReason.value.trim()};
        if(!Number.isInteger(payload.identifiedDays)||payload.identifiedDays<90||payload.identifiedDays>2555)return Espo.Ui.error('Identified retention must be between 90 days and 7 years.');
        if(!Number.isInteger(payload.anonymousDays)||payload.anonymousDays<30||payload.anonymousDays>730||payload.anonymousDays>payload.identifiedDays)return Espo.Ui.error('Anonymous retention must be between 30 days and 2 years and cannot exceed identified retention.');
        if(!Number.isInteger(payload.replayDays)||payload.replayDays<30||payload.replayDays>365)return Espo.Ui.error('Replay retention must be between 30 days and 1 year.');
        if(payload.legalHold&&payload.legalHoldReason.length<10)return Espo.Ui.error('Enter a legal-hold reason using at least 10 characters.');
        Espo.Ui.notifyWait(); try{const retention=await Espo.Ajax.putRequest('Nexa/tracking/retention',payload);this.workspace.retention=retention;this.renderRetention();Espo.Ui.success('Event retention policy saved.');}catch(error){Espo.Ui.error(error?.message||'The retention policy could not be saved.');}finally{Espo.Ui.notify(false);}
    }
    async purgeRetention() {
        const retention=this.workspace?.retention||{}; const count=Number(retention.storage?.eligibleEvents||0); if(!count||retention.policy?.legalHold)return;
        const limit=Number(retention.limits?.purgeBatchSize||5000); if(!window.confirm(`Permanently purge up to ${Math.min(count,limit).toLocaleString()} due events and their timeline projections? This action cannot be undone.`))return;
        Espo.Ui.notifyWait(); try{const result=await Espo.Ajax.postRequest('Nexa/tracking/retention/purge',{});this.workspace.retention=await Espo.Ajax.getRequest('Nexa/tracking/retention');this.renderRetention();Espo.Ui.success(`${Number(result.eventsDeleted||0).toLocaleString()} events permanently purged.`);}catch(error){Espo.Ui.error(error?.message||'Due event data could not be purged.');}finally{Espo.Ui.notify(false);}
    }
    openCreate() { this.openEditor({name:'',status:'active',integrationMode:'managed',allowedOrigins:[]}); }
    openEdit(event) { const item=(this.workspace?.sources||[]).find(row=>row.id===event.currentTarget.dataset.id); if(item)this.openEditor(item); }
    openEditor(item) { this.current={...item}; const form=this.element.querySelector('[data-source-form]'); form.reset(); form.elements.id.value=item.id||''; form.elements.name.value=item.name||''; form.elements.status.value=item.status||'active'; form.elements.integrationMode.value=item.integrationMode||'managed'; form.elements.allowedOrigins.value=(item.allowedOrigins||[]).join('\n'); this.element.querySelector('[data-source-dialog-title]').textContent=item.id?'Edit tracking source':'Add tracking source'; this.element.querySelector('[data-source-key-row]').hidden=!item.id; form.elements.publicKey.value=item.publicKey||''; this.element.querySelector('[data-rotate-key]').hidden=!item.id; this.element.querySelector('[data-source-modal]').hidden=false; form.elements.name.focus(); }
    closeEditor() { this.element.querySelector('[data-source-modal]').hidden=true; this.current=null; }
    async save(event) { event.preventDefault(); const form=event.currentTarget; const payload={name:form.elements.name.value.trim(),status:form.elements.status.value,integrationMode:form.elements.integrationMode.value,allowedOrigins:form.elements.allowedOrigins.value.split(/[\r\n,]+/).map(v=>v.trim()).filter(Boolean)}; if(!payload.name||!payload.allowedOrigins.length)return Espo.Ui.error('Enter a name and at least one approved website origin.'); Espo.Ui.notifyWait(); try { if(form.elements.id.value)await Espo.Ajax.putRequest(`Nexa/tracking/sources/${encodeURIComponent(form.elements.id.value)}`,payload); else await Espo.Ajax.postRequest('Nexa/tracking/sources',payload); this.closeEditor(); await this.load(); Espo.Ui.success('Tracking source saved.'); } catch(error){Espo.Ui.error(error?.message||'The tracking source could not be saved.');} finally{Espo.Ui.notify(false);} }
    async copyEmbed(event) { const item=(this.workspace?.sources||[]).find(row=>row.id===event.currentTarget.dataset.id); if(!item)return; try{await navigator.clipboard.writeText(item.embedCode);Espo.Ui.success('Embed code copied.');}catch(error){Espo.Ui.error('The embed code could not be copied.');} }
    async testSource(event) { const id=event.currentTarget.dataset.id;if(!id)return;Espo.Ui.notify('Sending diagnostic event...');try{await Espo.Ajax.postRequest(`Nexa/tracking/sources/${encodeURIComponent(id)}/test`,{});await this.load();Espo.Ui.success('Diagnostic event received by the customer timeline.');}catch(error){Espo.Ui.error(error?.message||'The diagnostic event could not be recorded.');}finally{Espo.Ui.notify(false);} }
    async rotateKey() { const id=this.element.querySelector('[data-source-form]').elements.id.value; if(!id)return; if(!window.confirm('Rotate this public key? The existing website script will stop collecting until its embed code is replaced.'))return; Espo.Ui.notifyWait(); try{const item=await Espo.Ajax.postRequest(`Nexa/tracking/sources/${encodeURIComponent(id)}/rotate-key`,{});this.element.querySelector('[data-source-form]').elements.publicKey.value=item.publicKey;await this.load();Espo.Ui.success('Public key rotated. Replace the website embed code.');}catch(error){Espo.Ui.error(error?.message||'The key could not be rotated.');}finally{Espo.Ui.notify(false);} }
    date(value) { if(!value)return 'No events yet'; const parsed=new Date(String(value).replace(' ','T')+'Z'); return Number.isNaN(parsed.getTime())?value:parsed.toLocaleString(); }
    escape(value) { const node=document.createElement('div');node.textContent=String(value??'');return node.innerHTML; }
});
