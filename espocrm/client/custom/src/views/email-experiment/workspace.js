define('custom:views/email-experiment/workspace', ['view','custom:workspace-table'], (Dep, WorkspaceTable) => class extends Dep {
    template = 'custom:email-experiment/workspace';
    events = {
        'click [data-action="refresh"]': 'load',
        'click [data-action="new"]': 'newExperiment',
        'click [data-action="close-create"]': 'closeCreate',
        'submit [data-create]': 'createExperiment',
        'input [data-search]': 'search',
        'click [data-sort]': 'sortRows',
        'click [data-experiment]': 'openExperiment',
        'click [data-control]': 'control',
        'click [data-action="close-detail"]': 'closeDetail',
        'submit [data-winner]': 'chooseWinner',
    };
    setup() { this.setPageTitle('Email experiments'); this.catalog = {experiments: [], sources: []}; this.sortKey = 'createdAt'; this.sortDirection = -1; this.tables = new WorkspaceTable(this); this.loadSequence = 0; }
    afterRender() {
        super.afterRender();
        if (!document.getElementById('nexa-email-experiment-style')) {
            const link = document.createElement('link'); link.id = 'nexa-email-experiment-style'; link.rel = 'stylesheet'; link.href = 'client/custom/css/email-experiments.css'; document.head.append(link);
        }
        this.load();
        this.element.querySelector('.ne-table-scroll').addEventListener('scroll',event => {const node=event.currentTarget;if(node.scrollTop+node.clientHeight>=node.scrollHeight-80)this.loadMore();});
        this.once('remove',()=>{clearTimeout(this.searchTimer);this.loadSequence++;});
    }
    async load() {
        this.error(''); this.element.querySelector('[data-loading]').hidden = false;
        const sequence=++this.loadSequence;
        try { const search=this.element.querySelector('[data-search]').value;const result = await Espo.Ajax.getRequest('Nexa/email-experiments/workspace',{search,offset:0,sort:this.sortKey,direction:this.sortDirection===1?'asc':'desc'});if(sequence!==this.loadSequence)return;this.catalog=result; this.renderRows(); if (this.currentId) await this.renderDetail(this.currentId); }
        catch (error) { this.error(error?.message || 'Experiments could not be loaded.'); }
        finally { this.element.querySelector('[data-loading]').hidden = true; }
    }
    error(message) { const node = this.element.querySelector('[data-error]'); node.textContent = message; node.hidden = !message; }
    escape(value) { return String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char])); }
    formatDate(value) { if (!value) return '-'; const date = new Date(value.replace(' ', 'T') + 'Z'); return Number.isNaN(date.getTime()) ? value : date.toLocaleString(); }
    search(){this.renderRows();clearTimeout(this.searchTimer);if(this.catalog.hasMore||this.remoteSearch)this.searchTimer=setTimeout(()=>{this.remoteSearch=true;this.load();},180);}
    async loadMore(){
        if(this.moreLoading||!this.catalog.hasMore)return;this.moreLoading=true;const sequence=this.loadSequence;
        try{const result=await Espo.Ajax.getRequest('Nexa/email-experiments/workspace',{search:this.element.querySelector('[data-search]').value,offset:this.catalog.nextOffset,sort:this.sortKey,direction:this.sortDirection===1?'asc':'desc'});if(sequence!==this.loadSequence)return;const existing=new Set(this.catalog.experiments.map(row=>row.id));this.catalog.experiments.push(...result.experiments.filter(row=>!existing.has(row.id)));this.catalog.hasMore=result.hasMore;this.catalog.nextOffset=result.nextOffset;this.catalog.total=result.total;this.renderRows();}
        catch(error){this.error(error?.message||'More experiments could not be loaded.');}finally{this.moreLoading=false;}
    }
    renderRows() {
        const query = this.element.querySelector('[data-search]').value.toLocaleLowerCase().trim();
        const rows = this.catalog.experiments.filter(row => `${row.name} ${row.status}`.toLocaleLowerCase().includes(query));
        rows.sort((a, b) => String(a[this.sortKey] || '').localeCompare(String(b[this.sortKey] || '')) * this.sortDirection);
        this.element.querySelector('[data-total]').textContent = `Total ${query&&!this.remoteSearch?rows.length:(this.catalog.total??rows.length)}`;
        this.element.querySelector('[data-rows]').innerHTML = rows.map(row => `<tr><td><button class="ne-name" data-experiment="${this.escape(row.id)}">${this.escape(row.name)}</button></td><td><span class="ne-status ne-${this.escape(row.status)}">${this.escape(row.status.replaceAll('_', ' '))}</span></td><td>${Number(row.testPercent)}% / ${Number(row.holdoutPercent)}%</td><td>${this.escape(row.winner || '-')}</td><td>${this.escape(this.formatDate(row.createdAt))}</td></tr>`).join('') || '<tr><td colspan="5">No experiments</td></tr>';
        this.element.querySelectorAll('[data-rows] tr').forEach(row => { if (row.children.length === 5) ['name','status','audience','winner','createdAt'].forEach((key,index) => {row.children[index].dataset.column = key;}); });
        this.tables.enhance(this.element.querySelector('[data-experiment-table]'), `email-experiments:${location.pathname}`);
    }
    sortRows(event) { const key = event.currentTarget.dataset.sort; this.sortDirection = this.sortKey === key ? -this.sortDirection : 1; this.sortKey = key; this.renderRows();this.load(); }
    newExperiment() {
        const form = this.element.querySelector('[data-create]'); form.hidden = false;
        const options = '<option value="">Choose an approved email</option>' + this.catalog.sources.map(row => `<option value="${this.escape(row.id)}">${this.escape(row.name)} - v${Number(row.version)}</option>`).join('');
        ['sourceA', 'sourceB'].forEach(key => { form.elements[key].innerHTML = options; });
        form.elements.name.focus();
    }
    closeCreate() { this.element.querySelector('[data-create]').hidden = true; }
    async createExperiment(event) {
        event.preventDefault(); const form = event.currentTarget; if (!form.reportValidity()) return;
        const sourceA = this.catalog.sources.find(row => row.id === form.elements.sourceA.value), sourceB = this.catalog.sources.find(row => row.id === form.elements.sourceB.value);
        if (!sourceA || !sourceB) { this.error('Choose two approved email variants.'); return; }
        const payload = {name: form.elements.name.value, sourceA: sourceA.id, versionA: sourceA.version, sourceB: sourceB.id, versionB: sourceB.version};
        ['testPercent','holdoutPercent','windowMinutes'].forEach(key => { payload[key] = Number(form.elements[key].value); });
        const submit = form.querySelector('[type="submit"]'); submit.disabled = true;
        try { const result = await Espo.Ajax.postRequest('Nexa/email-experiments', payload); this.closeCreate(); this.currentId = result.id; await this.load(); }
        catch (error) { this.error(error?.message || 'The experiment could not be created.'); }
        finally { submit.disabled = false; }
    }
    async openExperiment(event) { this.currentId = event.currentTarget.dataset.experiment; try { await this.renderDetail(this.currentId); } catch (error) { this.error(error?.message || 'Experiment details could not be loaded.'); } }
    closeDetail() { this.currentId = null; this.element.querySelector('[data-detail]').hidden = true; }
    async renderDetail(id) {
        const result = await Espo.Ajax.getRequest(`Nexa/email-experiments/${encodeURIComponent(id)}`);
        if(id!==this.currentId)return;
        const experiment = result.experiment, status = experiment.status;
        const controls = status === 'draft' ? ['start','cancel'] : status === 'paused' ? ['resume','cancel'] : ['testing','rollout'].includes(status) ? ['pause','cancel'] : status === 'awaiting_winner' ? ['cancel'] : [];
        const ready = experiment.decision_due_at && new Date(experiment.decision_due_at.replace(' ', 'T') + 'Z').getTime() <= Date.now() && ['testing','awaiting_winner'].includes(status);
        const detail = this.element.querySelector('[data-detail]'); detail.hidden = false;
        detail.innerHTML = `<header class="ne-detail-header"><h2>${this.escape(experiment.name)}</h2><div class="ne-toolbar">${controls.map(action => `<button class="btn ${action === 'start' || action === 'resume' ? 'btn-primary' : 'btn-default'}" data-control="${action}">${action[0].toUpperCase() + action.slice(1)}</button>`).join('')}<button class="btn btn-default" data-action="close-detail" aria-label="Close experiment"><span class="fas fa-times" aria-hidden="true"></span></button></div></header>
            <div class="ne-meta"><span class="ne-status">${this.escape(status.replaceAll('_', ' '))}</span><span>Decision window ends: ${this.escape(this.formatDate(experiment.decision_due_at))}</span></div>
            ${experiment.last_error ? `<div class="ne-warning" role="alert">${this.escape(experiment.last_error)}</div>` : ''}
            <div class="ne-table-scroll"><table class="table"><thead><tr><th>Variant</th><th>Assigned</th><th>Queued</th><th>Delivered</th><th>Failed</th><th>Unique clicks</th><th>Click rate</th><th>Recorded opens</th></tr></thead><tbody>${result.variants.map(variant => { const metric = result.metrics[variant.key]; return `<tr><th>${this.escape(variant.key)} - v${variant.version}</th>${['assigned','queued','delivered','failed','uniqueClicks'].map(key => `<td>${Number(metric[key] || 0)}</td>`).join('')}<td>${Number(metric.uniqueClickRate || 0)}%</td><td>${Number(metric.recordedOpens || 0)}</td></tr>`; }).join('')}</tbody></table></div>
            ${ready ? '<form data-winner class="ne-winner"><label>Winning variant<select name="variant" class="form-control" required><option value="A">A</option><option value="B">B</option></select></label><label>Decision reason<input name="reason" class="form-control" required minlength="3" maxlength="1000"></label><button class="btn btn-primary" type="submit">Select winner and roll out</button></form>' : ''}
            ${experiment.winner_variant ? `<p><strong>Winner ${this.escape(experiment.winner_variant)}</strong> ${this.escape(experiment.winner_reason)}</p>` : ''}
            <h3>Audience</h3><div class="ne-cohorts">${result.cohorts.map(row => `<span>${this.escape(row.cohort)} / ${this.escape(row.status)}: <strong>${Number(row.count)}</strong></span>`).join('')}</div>
            <h3>History</h3><ol class="ne-history">${result.history.map(row => `<li><strong>${this.escape(row.type.replaceAll('_', ' '))}</strong><time>${this.escape(this.formatDate(row.occurredAt))}</time></li>`).join('')}</ol>`;
        const results = detail.querySelector('.ne-table-scroll');
        results.tabIndex = 0;
        results.setAttribute('role','region');
        results.setAttribute('aria-label','Variant results');
    }
    async control(event) {
        const action = event.currentTarget.dataset.control;
        if (action === 'cancel' && !window.confirm('Cancel remaining experiment recipients? Messages already accepted for delivery cannot be recalled.')) return;
        event.currentTarget.disabled = true;
        try { await Espo.Ajax.postRequest(`Nexa/email-experiments/${encodeURIComponent(this.currentId)}/control`, {action}); this.error(''); await this.load(); }
        catch (error) { this.error(error?.message || 'The experiment could not be updated.'); event.currentTarget.disabled = false; }
    }
    async chooseWinner(event) {
        event.preventDefault(); const form = event.currentTarget; if (!form.reportValidity()) return;
        const submit = form.querySelector('[type="submit"]'); submit.disabled = true;
        try { await Espo.Ajax.postRequest(`Nexa/email-experiments/${encodeURIComponent(this.currentId)}/control`, {action: 'winner', variant: form.elements.variant.value, reason: form.elements.reason.value}); this.error(''); await this.load(); }
        catch (error) { this.error(error?.message || 'The winner could not be selected.'); submit.disabled = false; }
    }
});
