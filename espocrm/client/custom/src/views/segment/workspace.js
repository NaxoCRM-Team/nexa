define('custom:views/segment/workspace', ['view'], Dep => class extends Dep {
    template = 'custom:segment/workspace';

    events = {
        'click [data-action="reload"]': 'load', 'click [data-action="new-segment"]': 'newSegment',
        'click [data-action="edit-segment"]': 'editSegment', 'click [data-action="close-editor"]': 'closeEditor',
        'click [data-action="next-step"]': 'nextStep', 'click [data-action="previous-step"]': 'previousStep',
        'click [data-action="go-step"]': 'goStep', 'click [data-action="add-rule"]': 'addRule',
        'click [data-action="add-exclusion"]': 'addExclusion', 'click [data-action="remove-rule"]': 'removeRule',
        'click [data-action="preview-segment"]': 'previewSegment', 'click [data-action="recalculate-segment"]': 'recalculateSegment',
        'click [data-action="manage-members"]': 'manageMembers', 'click [data-action="duplicate-segment"]': 'duplicateSegment',
        'click [data-action="export-segment"]': 'exportSegment', 'click [data-action="archive-segment"]': 'archiveSegment',
        'input [data-segment-search]': 'renderSegments', 'change [data-segment-type]': 'renderSegments',
        'change [data-segment-folder]': 'renderSegments', 'change [name="type"]': 'toggleType',
        'change [name="snapshotFromRules"]': 'toggleType', 'change [data-rule-field]': 'changeRuleField',
        'change [data-rule-operator]': 'changeRuleOperator', 'submit [data-segment-form]': 'saveSegment',
    };

    setup() { this.setPageTitle('Segments'); this.workspace = null; this.currentSegment = null; this.rules = []; this.exclusionRules = []; this.step = 1; }

    afterRender() { super.afterRender(); document.body.classList.add('nexa-segments-page'); this.once('remove', () => document.body.classList.remove('nexa-segments-page')); this.load(); }

    async load() {
        this.setState('loading');
        try { this.workspace = await Espo.Ajax.getRequest('Nexa/segments/workspace'); this.renderFolderOptions(); this.renderSummary(); this.renderSegments(); this.setState('ready'); }
        catch (error) { this.setState('error'); Espo.Ui.error(error?.message || 'Segments could not be loaded.'); }
    }

    setState(state) { this.element.querySelectorAll('[data-segment-state]').forEach(node => { node.hidden = node.dataset.segmentState !== state; node.style.display = node.hidden ? 'none' : ''; }); }

    renderFolderOptions() {
        const folders = this.workspace?.folders || [];
        this.element.querySelector('[data-segment-folder]').innerHTML = '<option value="">All folders</option>' + folders.map(value => `<option value="${this.escape(value)}">${this.escape(value)}</option>`).join('');
        this.element.querySelector('[data-folder-options]').innerHTML = folders.map(value => `<option value="${this.escape(value)}"></option>`).join('');
    }

    renderSummary() {
        const segments = this.workspace?.segments || []; const sum = key => segments.reduce((total, item) => total + Number(item[key] || 0), 0);
        this.text('[data-summary-total]', segments.length); this.text('[data-summary-members]', sum('memberCount')); this.text('[data-summary-eligible]', sum('eligibleCount')); this.text('[data-summary-suppressed]', sum('suppressedCount'));
    }

    renderSegments() {
        if (!this.workspace) return;
        const query = (this.element.querySelector('[data-segment-search]')?.value || '').trim().toLowerCase();
        const type = this.element.querySelector('[data-segment-type]')?.value || ''; const folder = this.element.querySelector('[data-segment-folder]')?.value || '';
        const segments = (this.workspace.segments || []).filter(item => (!type || item.type === type) && (!folder || item.folder === folder) && (!query || `${item.name || ''} ${item.description || ''} ${item.folder || ''}`.toLowerCase().includes(query)));
        this.element.querySelector('[data-segment-list]').innerHTML = segments.map(item => {
            const active = item.type === 'dynamic'; const icon = active ? 'fa-bolt' : 'fa-camera';
            const description = item.description || (active ? `${item.rules.length} inclusion filter${item.rules.length === 1 ? '' : 's'}` : item.snapshotFromRules ? 'Fixed snapshot from filters' : 'Manual membership');
            const primaryAction = active ? `<button class="btn btn-icon" type="button" data-action="recalculate-segment" data-id="${this.escape(item.id)}" title="Recalculate"><span class="fas fa-sync-alt"></span></button>` : `<button class="btn btn-icon" type="button" data-action="manage-members" data-id="${this.escape(item.id)}" title="Manage members"><span class="fas fa-user-plus"></span></button>`;
            return `<tr><td><div class="nexa-segment-name"><span class="fas ${icon}"></span><div><strong>${this.escape(item.name)}</strong><small>${this.escape(description)}</small></div></div></td><td><span class="nexa-segment-badge is-${item.type}">${active ? 'Active' : 'Static'}</span></td><td>${this.escape(item.folder || 'Unfiled')}</td><td>${item.memberCount}</td><td>${item.eligibleCount}</td><td>${item.suppressedCount}</td><td>${this.escape(this.dateTime(item.lastCalculatedAt || item.modifiedAt))}</td><td><div class="nexa-segment-actions"><button class="btn btn-icon" type="button" data-action="edit-segment" data-id="${this.escape(item.id)}" title="Edit"><span class="fas fa-pen"></span></button>${primaryAction}<button class="btn btn-icon" type="button" data-action="duplicate-segment" data-id="${this.escape(item.id)}" title="Clone"><span class="fas fa-copy"></span></button><button class="btn btn-icon" type="button" data-action="export-segment" data-id="${this.escape(item.id)}" title="Export CSV"><span class="fas fa-download"></span></button><button class="btn btn-icon is-danger" type="button" data-action="archive-segment" data-id="${this.escape(item.id)}" title="Archive"><span class="fas fa-archive"></span></button></div></td></tr>`;
        }).join('');
        this.text('[data-segment-count]', `${segments.length} segment${segments.length === 1 ? '' : 's'}`);
        const empty = this.element.querySelector('[data-segment-empty]'); empty.hidden = segments.length > 0; empty.style.display = empty.hidden ? 'none' : '';
        this.element.querySelector('.nexa-segment-table-wrap').style.display = segments.length ? '' : 'none';
    }

    newSegment() { this.openEditor(null); }
    editSegment(event) { this.openEditor((this.workspace.segments || []).find(item => item.id === event.currentTarget.dataset.id)); }

    openEditor(segment) {
        this.currentSegment = segment || null; this.rules = (segment?.rules || []).map(rule => ({...rule})); this.exclusionRules = (segment?.exclusionRules || []).map(rule => ({...rule}));
        const form = this.element.querySelector('[data-segment-form]'); form.reset();
        form.elements.name.value = segment?.name || ''; form.elements.description.value = segment?.description || ''; form.elements.type.value = segment?.type || 'dynamic'; form.elements.matchMode.value = segment?.matchMode || 'all';
        form.elements.folder.value = segment?.folder || ''; form.elements.accessLevel.value = segment?.accessLevel || 'everyone'; form.elements.snapshotFromRules.checked = segment?.snapshotFromRules ?? true;
        this.text('[data-editor-title]', segment ? `Edit ${segment.name}` : 'Create segment'); this.element.querySelector('[data-behavior-note] p').textContent = this.workspace.behavioralRulesMessage || '';
        this.element.querySelector('[data-segment-error="name"]').textContent = ''; this.element.querySelector('[data-editor-status]').textContent = ''; this.resetPreview(); this.toggleType(); this.showStep(1);
        const editor = this.element.querySelector('[data-segment-editor]'); editor.hidden = false; editor.style.display = 'grid'; document.body.classList.add('nexa-modal-open');
    }

    closeEditor() { const editor = this.element.querySelector('[data-segment-editor]'); editor.hidden = true; editor.style.display = 'none'; document.body.classList.remove('nexa-modal-open'); }

    toggleType() {
        const form = this.element.querySelector('[data-segment-form]'); const active = form.elements.type.value === 'dynamic';
        if (this.element.querySelector('[data-segment-rule]')) this.syncRules();
        const snapshot = this.element.querySelector('[data-static-snapshot]'); snapshot.hidden = active; snapshot.style.display = active ? 'none' : 'flex';
        const usesFilters = active || form.elements.snapshotFromRules.checked;
        if (usesFilters && !this.rules.length) this.rules = [{field: 'lifecycleStage', operator: 'equals', value: ''}];
        this.renderRules(); this.resetPreview(usesFilters ? 'Add filters and run a preview.' : 'This segment starts empty. Add contacts after saving.');
    }

    showStep(step) {
        this.step = Math.max(1, Math.min(3, Number(step) || 1));
        this.element.querySelectorAll('[data-builder-step]').forEach(node => { node.hidden = Number(node.dataset.builderStep) !== this.step; node.style.display = node.hidden ? 'none' : ''; });
        this.element.querySelectorAll('[data-action="go-step"]').forEach(node => { const value = Number(node.dataset.step); node.classList.toggle('is-active', value === this.step); node.classList.toggle('is-complete', value < this.step); });
        this.element.querySelector('[data-action="previous-step"]').hidden = this.step === 1; this.element.querySelector('[data-action="next-step"]').hidden = this.step === 3; this.element.querySelector('[data-action="save-segment"]').hidden = this.step !== 3;
        if (this.step === 3) { this.renderReview(); setTimeout(() => this.element.querySelector('[name="name"]')?.focus(), 0); }
    }

    nextStep() { if (this.step === 2 && !this.validateRules(this.payload())) return; this.showStep(this.step + 1); }
    previousStep() { this.showStep(this.step - 1); }
    goStep(event) { const target = Number(event.currentTarget.dataset.step); if (target > this.step && this.step === 2 && !this.validateRules(this.payload())) return; this.showStep(target); }

    readRules(selector = '[data-segment-rule]') { return [...this.element.querySelectorAll(selector)].map(node => ({field: node.querySelector('[data-rule-field]').value, operator: node.querySelector('[data-rule-operator]').value, value: node.querySelector('[data-rule-value]').value.trim()})); }

    ruleMarkup(rule, index, kind) {
        const fields = this.workspace.fieldCatalog.map(item => `<option value="${this.escape(item.id)}" ${item.id === rule.field ? 'selected' : ''}>${this.escape(item.name)}</option>`).join('');
        const selectedField = this.workspace.fieldCatalog.find(item => item.id === rule.field) || this.workspace.fieldCatalog[0]; const allowed = new Set(selectedField?.operators || []);
        const compatible = this.workspace.operators.filter(item => allowed.has(item.id)); if (!allowed.has(rule.operator)) rule.operator = compatible[0]?.id || 'equals';
        const operators = compatible.map(item => `<option value="${this.escape(item.id)}" ${item.id === rule.operator ? 'selected' : ''}>${this.escape(item.name)}</option>`).join(''); const disabled = ['isEmpty', 'isNotEmpty'].includes(rule.operator);
        return `<article class="nexa-segment-rule" data-segment-rule data-kind="${kind}" data-index="${index}"><label><span>Property</span><select class="form-control input-sm" data-rule-field>${fields}</select></label><label><span>Condition</span><select class="form-control input-sm" data-rule-operator>${operators}</select></label><label><span>Value</span><input class="form-control input-sm" data-rule-value value="${this.escape(rule.value || '')}" ${disabled ? 'disabled' : ''}></label><button class="btn btn-icon is-danger" type="button" data-action="remove-rule" data-kind="${kind}" data-index="${index}" title="Remove filter"><span class="fas fa-times"></span></button></article>`;
    }

    renderRules() { if (!this.workspace) return; this.element.querySelector('[data-segment-rules]').innerHTML = this.rules.map((rule, index) => this.ruleMarkup(rule, index, 'include')).join(''); this.element.querySelector('[data-exclusion-rules]').innerHTML = this.exclusionRules.map((rule, index) => this.ruleMarkup(rule, index, 'exclude')).join(''); }
    syncRules() { this.rules = this.readRules('[data-segment-rule][data-kind="include"]'); this.exclusionRules = this.readRules('[data-segment-rule][data-kind="exclude"]'); }
    addRule() { this.syncRules(); this.rules.push({field: 'lifecycleStage', operator: 'equals', value: ''}); this.renderRules(); }
    addExclusion() { this.syncRules(); this.exclusionRules.push({field: 'doNotContact', operator: 'equals', value: '1'}); this.renderRules(); }
    removeRule(event) { this.syncRules(); const list = event.currentTarget.dataset.kind === 'exclude' ? this.exclusionRules : this.rules; list.splice(Number(event.currentTarget.dataset.index), 1); this.renderRules(); }
    changeRuleField() { this.syncRules(); this.renderRules(); }
    changeRuleOperator() { this.syncRules(); this.renderRules(); }

    payload() {
        const form = this.element.querySelector('[data-segment-form]'); this.syncRules(); const type = form.elements.type.value; const snapshotFromRules = type === 'static' && form.elements.snapshotFromRules.checked;
        return {recordType: 'Contact', name: form.elements.name.value.trim(), description: form.elements.description.value.trim(), folder: form.elements.folder.value.trim(), accessLevel: form.elements.accessLevel.value, type, snapshotFromRules, matchMode: form.elements.matchMode.value, rules: type === 'dynamic' || snapshotFromRules ? this.rules : [], exclusionRules: type === 'dynamic' || snapshotFromRules ? this.exclusionRules : []};
    }

    validate(payload) { this.element.querySelector('[data-segment-error="name"]').textContent = ''; if (!payload.name) { this.element.querySelector('[data-segment-error="name"]').textContent = 'Enter a clear segment name.'; return false; } return this.validateRules(payload); }
    validateRules(payload) { const usesFilters = payload.type === 'dynamic' || payload.snapshotFromRules; if (usesFilters && !payload.rules.length) { Espo.Ui.error('Add at least one inclusion filter.'); return false; } const invalid = [...payload.rules, ...payload.exclusionRules].some(rule => !['isEmpty', 'isNotEmpty'].includes(rule.operator) && !rule.value); if (usesFilters && invalid) { Espo.Ui.error('Enter a value for every filter.'); return false; } return true; }

    async previewSegment() {
        const payload = this.payload(); if (payload.type === 'static' && !payload.snapshotFromRules) { this.resetPreview('This segment starts empty and is managed manually.'); return; } if (!this.validateRules(payload)) return;
        this.text('[data-editor-status]', 'Calculating preview...');
        try { const result = await Espo.Ajax.postRequest('Nexa/segments/preview', {matchMode: payload.matchMode, rules: payload.rules, exclusionRules: payload.exclusionRules}); this.renderPreview(result); this.text('[data-editor-status]', `Previewed ${result.memberCount} matching contacts.`); }
        catch (error) { this.text('[data-editor-status]', ''); Espo.Ui.error(error?.message || 'The preview could not be calculated.'); }
    }

    renderPreview(result) {
        this.text('[data-preview-members]', result.memberCount || 0); this.text('[data-preview-eligible]', result.eligibleCount || 0); this.text('[data-preview-suppressed]', result.suppressedCount || 0); const list = this.element.querySelector('[data-preview-list]');
        list.innerHTML = (result.records || []).slice(0, 8).map(record => `<div class="nexa-segment-preview-person"><strong>${this.escape(record.name || 'Unnamed contact')}</strong><span>${this.escape([record.accountName, record.country, record.lifecycleStage].filter(Boolean).join(' / ') || 'No additional details')}</span></div>`).join('') || '<p>No contacts currently match these filters.</p>';
        if ((result.records || []).length > 8) list.insertAdjacentHTML('beforeend', `<p>And ${result.records.length - 8} more in this preview.</p>`);
    }

    renderReview() { const payload = this.payload(); const type = payload.type === 'dynamic' ? 'Active segment' : payload.snapshotFromRules ? 'Static snapshot' : 'Static manual segment'; this.element.querySelector('[data-segment-review]').innerHTML = `<strong>${this.escape(type)}</strong><span>${payload.rules.length} inclusion filter${payload.rules.length === 1 ? '' : 's'}</span><span>${payload.exclusionRules.length} exclusion${payload.exclusionRules.length === 1 ? '' : 's'}</span><span>${this.escape(payload.accessLevel === 'everyone' ? 'Workspace access' : payload.accessLevel === 'owner' ? 'Owner and administrators' : 'Administrators only')}</span>`; }
    resetPreview(message = 'Add filters and run a preview.') { this.text('[data-preview-members]', 0); this.text('[data-preview-eligible]', 0); this.text('[data-preview-suppressed]', 0); const list = this.element.querySelector('[data-preview-list]'); if (list) list.innerHTML = `<p>${this.escape(message)}</p>`; }

    async saveSegment(event) {
        event.preventDefault(); const payload = this.payload(); if (!this.validate(payload)) { this.showStep(3); return; } Espo.Ui.notify('Saving and processing segment...');
        try { if (this.currentSegment) await Espo.Ajax.putRequest(`Nexa/segments/${encodeURIComponent(this.currentSegment.id)}`, payload); else await Espo.Ajax.postRequest('Nexa/segments', payload); Espo.Ui.success(payload.type === 'dynamic' ? 'Active segment saved and processed.' : 'Static segment saved.'); this.closeEditor(); await this.load(); }
        catch (error) { Espo.Ui.notify(false); Espo.Ui.error(error?.message || 'The segment could not be saved.'); }
    }

    async recalculateSegment(event) { Espo.Ui.notify('Recalculating segment...'); try { const result = await Espo.Ajax.postRequest(`Nexa/segments/${encodeURIComponent(event.currentTarget.dataset.id)}/recalculate`); Espo.Ui.success(`${result.memberCount} contacts match. ${result.enteredCount} entered and ${result.exitedCount} exited.`); await this.load(); } catch (error) { Espo.Ui.notify(false); Espo.Ui.error(error?.message || 'The segment could not be recalculated.'); } }
    manageMembers(event) { window.location.hash = `#TargetList/view/${encodeURIComponent(event.currentTarget.dataset.id)}`; }
    async duplicateSegment(event) { Espo.Ui.notify('Cloning segment...'); try { await Espo.Ajax.postRequest(`Nexa/segments/${encodeURIComponent(event.currentTarget.dataset.id)}/duplicate`); Espo.Ui.success('Segment cloned.'); await this.load(); } catch (error) { Espo.Ui.notify(false); Espo.Ui.error(error?.message || 'The segment could not be cloned.'); } }

    async exportSegment(event) {
        const segment = (this.workspace.segments || []).find(item => item.id === event.currentTarget.dataset.id); Espo.Ui.notify('Preparing CSV...');
        try { const result = await Espo.Ajax.getRequest(`Nexa/segments/${encodeURIComponent(event.currentTarget.dataset.id)}/export`); const columns = ['id','name','email','phone','account','lifecycleStage','marketingStatus','country']; const quote = value => `"${String(value ?? '').replace(/"/g, '""')}"`; const csv = [columns.join(','), ...(result.records || []).map(record => columns.map(key => quote(record[key])).join(','))].join('\r\n'); const link = document.createElement('a'); link.href = URL.createObjectURL(new Blob([csv], {type: 'text/csv;charset=utf-8'})); link.download = `${(segment?.name || 'segment').replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '').toLowerCase() || 'segment'}.csv`; link.click(); setTimeout(() => URL.revokeObjectURL(link.href), 1000); Espo.Ui.success('Segment export downloaded.'); }
        catch (error) { Espo.Ui.notify(false); Espo.Ui.error(error?.message || 'The segment could not be exported.'); }
    }

    archiveSegment(event) { const segment = (this.workspace.segments || []).find(item => item.id === event.currentTarget.dataset.id); if (!segment) return; this.confirm({message: `Archive ${segment.name}? Membership history will be retained.`, confirmText: 'Archive segment'}, async () => { Espo.Ui.notify('Archiving segment...'); try { await Espo.Ajax.postRequest(`Nexa/segments/${encodeURIComponent(segment.id)}/archive`); Espo.Ui.success('Segment archived.'); await this.load(); } catch (error) { Espo.Ui.notify(false); Espo.Ui.error(error?.message || 'The segment could not be archived.'); } }); }

    dateTime(value) { if (!value) return 'Not processed'; const parsed = new Date(String(value).replace(' ', 'T') + (String(value).includes('Z') ? '' : 'Z')); return Number.isNaN(parsed.getTime()) ? String(value) : parsed.toLocaleString(); }
    text(selector, value) { const node = this.element.querySelector(selector); if (node) node.textContent = String(value); }
    escape(value) { const node = document.createElement('div'); node.textContent = String(value ?? ''); return node.innerHTML; }
});
