define('custom:views/case/record/detail-workspace', ['crm:views/case/record/detail', 'helpers/record-modal'], (Dep, RecordModalHelper) => class extends Dep {
    setup() {
        super.setup();
        document.body.classList.add('nexa-case-record-page');
        this.listenTo(this.model, 'sync change', () => this.refreshWorkspace());
        this.once('remove', () => document.body.classList.remove('nexa-case-record-page'));
    }
    afterRender() { const result = super.afterRender(); this.prepareWorkspace(); return result; }
    async prepareWorkspace() {
        if (this.workspacePending) return;
        const root = this.element; root?.classList.add('nexa-case-detail-workspace','nexa-case-workspace-loading');
        if (root?.querySelector('[data-nexa-case-workspace]')) { root.classList.remove('nexa-case-workspace-loading'); return; }
        this.workspacePending = true;
        try { await this.model.fetch(); } catch (error) { /* Native record remains available. */ }
        finally { this.workspacePending = false; }
        if (!this.isRendered()) return;
        this.renderWorkspace(); root?.classList.remove('nexa-case-workspace-loading');
    }
    isPortalUser() { return this.getUser().isPortal?.() || this.getUser().get('type') === 'portal'; }
    renderWorkspace() {
        const root = this.element;
        const nativeRecord = root?.querySelector(':scope > .detail') || root?.querySelector('.detail');
        if (!nativeRecord || nativeRecord.querySelector('[data-nexa-case-workspace]')) return;
        const portal = this.isPortalUser();
        const shell = document.createElement('section');
        shell.className = `nexa-case-workspace${portal ? ' is-portal' : ''}`; shell.dataset.nexaCaseWorkspace = 'true';
        shell.innerHTML = `
            <header class="nexa-case-toolbar">
                <div class="nexa-case-identity"><a href="#Case" class="nexa-case-back" aria-label="Back to Cases"><span class="fas fa-arrow-left"></span></a>
                    <span class="nexa-case-avatar"><span class="fas fa-headset"></span></span>
                    <div><p>${portal ? 'Support request' : 'Service case'}</p><h2 data-case-title></h2><span data-case-subtitle></span></div>
                </div><div class="nexa-case-native-actions" data-case-actions></div>
            </header>
            <div class="nexa-case-grid">
                <aside class="nexa-case-profile" aria-label="Case information">
                    ${portal ? '' : `<div class="nexa-case-quick-actions" role="toolbar" aria-label="Case actions">
                        ${this.actionButton('response','fas fa-reply','Response')}${this.actionButton('task','far fa-check-square','Task')}
                        ${this.actionButton('meeting','far fa-calendar','Meeting')}${this.actionButton('email','far fa-envelope','Email')}
                    </div>`}
                    <section><div class="nexa-case-section-heading"><p>Request</p><h3>Case information</h3></div>
                        <dl class="nexa-case-facts">${this.fact('Status','status')}${this.fact('Priority','priority')}${this.fact('Category','category')}${this.fact('Type','type')}${this.fact('Created','createdAt')}${this.fact('Updated','modifiedAt')}</dl>
                    </section>
                    ${portal ? '' : `<section><div class="nexa-case-section-heading"><p>Ownership</p><h3>Service team</h3></div>
                        <dl class="nexa-case-facts">${this.fact('Case owner','assignedUser')}${this.fact('Teams','teams')}${this.fact('Inbound channel','inboundEmail')}</dl></section>`}
                </aside>
                <main class="nexa-case-main">
                    <section class="nexa-case-metrics" aria-label="Service commitments">
                        ${this.metric('slaStatus','SLA status','fas fa-stopwatch')}${this.metric('firstResponseDueAt','First response','far fa-clock')}${this.metric('resolutionDueAt','Resolution due','far fa-calendar-check')}
                    </section>
                    <nav class="nexa-case-tabs" role="tablist" aria-label="Case workspace">
                        ${this.tab('overview','Overview',true)}${this.tab('activity','Activity')}${this.tab('emails','Emails')}${this.tab('tasks','Tasks')}
                    </nav>
                    <section class="nexa-case-panel is-active" role="tabpanel" data-case-panel="overview">
                        <div class="nexa-case-panel-heading"><div><p>Case summary</p><h3>Request and resolution context</h3></div></div>
                        <article class="nexa-case-description" data-case-description><p>No description recorded.</p></article>
                        <div class="nexa-case-service-events">
                            ${this.signal('First responded','firstRespondedAt')}${this.signal('Resolved','resolvedAt')}${this.signal('Escalated','escalatedAt')}${this.signal('Escalation reason','escalationReason')}
                        </div>
                    </section>
                    <section class="nexa-case-panel" role="tabpanel" data-case-panel="activity" hidden><div data-case-native-panel="activities"></div><div data-case-native-panel="history"></div></section>
                    <section class="nexa-case-panel" role="tabpanel" data-case-panel="emails" hidden><div data-case-native-panel="emails"></div></section>
                    <section class="nexa-case-panel" role="tabpanel" data-case-panel="tasks" hidden><div data-case-native-panel="tasks"></div></section>
                </main>
                <aside class="nexa-case-context" aria-label="Case context">
                    <section class="nexa-case-context-card"><div class="nexa-case-section-heading"><p>Service level</p><h3>Commitment status</h3></div>
                        <div class="nexa-case-sla-banner" data-case-sla-banner></div>
                        <dl class="nexa-case-facts">${this.fact('First response due','firstResponseDueAt')}${this.fact('Resolution due','resolutionDueAt')}${this.fact('First responded','firstRespondedAt')}${this.fact('Resolved','resolvedAt')}</dl>
                    </section>
                    ${portal ? '' : `<section class="nexa-case-context-card"><div class="nexa-case-section-heading"><p>Connected records</p><h3>Customer and commercial context</h3></div>
                        <dl class="nexa-case-facts">${this.fact('Account','account')}${this.fact('Contact','contact')}${this.fact('Lead','lead')}${this.fact('Opportunity','opportunity')}${this.fact('Quote reference','nexaQuoteId')}</dl>
                    </section>`}
                </aside>
            </div>`;
        nativeRecord.prepend(shell); this.placeNativeViews(nativeRecord,shell); this.bindTabs(shell); this.bindActions(shell); this.refreshWorkspace();
    }
    actionButton(type,icon,label) { return `<button type="button" class="btn btn-link" data-case-action="${type}"><span class="${icon}"></span><span>${label}</span></button>`; }
    fact(label,field) { return `<div><dt>${label}</dt><dd data-case-field="${field}">--</dd></div>`; }
    metric(field,label,icon) { return `<article><span class="${icon}"></span><div><strong data-case-metric="${field}">--</strong><small>${label}</small></div></article>`; }
    signal(label,field) { return `<article><small>${label}</small><strong data-case-signal="${field}">Not recorded</strong></article>`; }
    tab(name,label,selected=false) { return `<button type="button" role="tab" data-case-tab="${name}" aria-selected="${String(selected)}">${label}</button>`; }
    placeNativeViews(nativeRecord,shell) {
        const grid = nativeRecord.querySelector(':scope > .record-grid');
        const actions = shell.querySelector('[data-case-actions]');
        [...new Set([...nativeRecord.querySelectorAll(':scope > .record-buttons, :scope > .edit-buttons'),...this.element.querySelectorAll(':scope > .record-buttons, :scope > .edit-buttons')])].forEach(node => actions.append(node));
        ['activities','history','tasks','emails'].forEach(name => {
            const panel = grid?.querySelector(`.side [data-name="${name}"], .bottom [data-name="${name}"]`) || nativeRecord.querySelector(`[data-name="${name}"]`);
            const host = shell.querySelector(`[data-case-native-panel="${name}"]`); if(panel&&host) host.append(panel);
        });
        grid?.classList.add('nexa-case-native-grid-host');
    }
    bindTabs(shell) {
        const tabs=[...shell.querySelectorAll('[data-case-tab]')];
        tabs.forEach((button,index)=>{
            button.addEventListener('click',()=>this.activateTab(shell,button.dataset.caseTab));
            button.addEventListener('keydown',event=>{
                if(!['ArrowLeft','ArrowRight','Home','End'].includes(event.key)) return; event.preventDefault();
                let next=event.key==='Home'?0:event.key==='End'?tabs.length-1:(index+(event.key==='ArrowRight'?1:-1)+tabs.length)%tabs.length;
                tabs[next].focus(); this.activateTab(shell,tabs[next].dataset.caseTab);
            });
        });
    }
    activateTab(shell,name) {
        shell.querySelectorAll('[data-case-tab]').forEach(tab=>tab.setAttribute('aria-selected',String(tab.dataset.caseTab===name)));
        shell.querySelectorAll('[data-case-panel]').forEach(panel=>{const active=panel.dataset.casePanel===name;panel.hidden=!active;panel.classList.toggle('is-active',active);});
    }
    bindActions(shell) {
        shell.querySelector('[data-case-action="response"]')?.addEventListener('click',()=>this.recordResponse());
        shell.querySelector('[data-case-action="task"]')?.addEventListener('click',()=>this.openRelated('Task'));
        shell.querySelector('[data-case-action="meeting"]')?.addEventListener('click',()=>this.openRelated('Meeting'));
        shell.querySelector('[data-case-action="email"]')?.addEventListener('click',()=>this.openRelated('Email'));
    }
    async recordResponse() {
        if(!this.getAcl().checkModel(this.model,'edit')) { Espo.Ui.error('You do not have permission to update this Case.'); return; }
        Espo.Ui.notify('Recording response...');
        try { await Espo.Ajax.postRequest(`Nexa/cases/${this.model.id}/response`,{}); await this.model.fetch(); this.refreshWorkspace(); Espo.Ui.success('First response recorded.'); }
        catch(error){Espo.Ui.notify(false);Espo.Ui.error(error?.message||'The response could not be recorded.');}
    }
    async openRelated(entityType) {
        if(!this.getAcl().checkScope(entityType,'create')) { Espo.Ui.error(`You do not have permission to create ${entityType.toLowerCase()} records.`); return; }
        const attributes={parentType:'Case',parentId:this.model.id,parentName:this.model.get('name'),assignedUserId:this.getUser().id,assignedUserName:this.getUser().get('name')};
        if(entityType==='Meeting') attributes.status='Planned';
        if(entityType==='Email') { attributes.to=this.model.get('contactName')||''; attributes.name=`Re: [#${this.model.get('number')}] ${this.model.get('name')}`; }
        try { await new RecordModalHelper().showCreate(this,{entityType,attributes,focusForCreate:true,afterSave:()=>this.model.fetch()}); }
        catch(error){Espo.Ui.error(this.translate('Error occurred'));}
    }
    refreshWorkspace() {
        const shell=this.element?.querySelector('[data-nexa-case-workspace]'); if(!shell) return;
        shell.querySelector('[data-case-title]').textContent=this.value('name')||'Untitled Case';
        shell.querySelector('[data-case-subtitle]').textContent=`Case #${this.value('number')||'--'} · ${this.value('accountName')||this.value('contactName')||'Customer request'}`;
        shell.querySelector('[data-case-description] p').textContent=this.value('description')||'No description recorded.';
        shell.querySelectorAll('[data-case-field]').forEach(node=>{node.textContent=this.display(node.dataset.caseField);});
        shell.querySelectorAll('[data-case-metric]').forEach(node=>{node.textContent=this.display(node.dataset.caseMetric,true);});
        shell.querySelectorAll('[data-case-signal]').forEach(node=>{node.textContent=this.display(node.dataset.caseSignal,true);});
        const status=this.value('slaStatus')||'Not Started'; const banner=shell.querySelector('[data-case-sla-banner]');
        if(banner){banner.className=`nexa-case-sla-banner is-${status.toLowerCase().replace(/\s+/g,'-')}`;banner.innerHTML=`<span class="fas fa-stopwatch"></span><strong>${this.escape(status)}</strong>`;}
    }
    value(field) { return this.model.get(field); }
    display(field,compact=false) {
        const value=this.value(field); if(value===null||value===undefined||value==='') return compact?'Not recorded':'--';
        if(field.endsWith('At')) { const date=new Date(String(value).replace(' ','T')+'Z'); return Number.isNaN(date.getTime())?String(value):date.toLocaleString(); }
        if(['assignedUser','account','contact','lead','opportunity','inboundEmail'].includes(field)) return this.value(`${field}Name`)||'--';
        if(field==='teams') { const names=this.value('teamsNames')||{}; return Object.values(names).join(', ')||'--'; }
        return String(value);
    }
    escape(value) { const span=document.createElement('span');span.textContent=String(value);return span.innerHTML; }
});
