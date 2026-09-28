define('custom:views/form/workspace', ['view', 'custom:workspace-table'], (Dep, WorkspaceTable) => class extends Dep {
    template = 'custom:form/workspace';

    events = {
        'click [data-action="reload"]': 'load',
        'click [data-action="switch-tab"]': 'switchTab',
        'click [data-action="new-form"]': 'newForm',
        'click [data-action="edit-form"]': 'editForm',
        'click [data-action="close-editor"]': 'closeEditor',
        'click [data-action="add-field"]': 'addField',
        'click [data-action="remove-field"]': 'removeField',
        'click [data-action="move-field-up"]': 'moveFieldUp',
        'click [data-action="move-field-down"]': 'moveFieldDown',
        'change [data-action="toggle-required"]': 'toggleRequired',
        'change [data-action="change-mapping"]': 'changeMapping',
        'click [data-action="add-condition"]': 'addCondition',
        'click [data-action="remove-condition"]': 'removeCondition',
        'input [data-field-search]': 'renderFieldCatalog',
        'input [data-form-search]': 'renderForms',
        'change [data-form-status]': 'renderForms',
        'input [data-submission-search]': 'queueSubmissionSearch',
        'click [data-table-sort]': 'sortTable',
        'click [data-action="delete-submission"]': 'openSubmissionDelete',
        'click [data-action="cancel-submission-delete"]': 'closeSubmissionDelete',
        'click [data-action="confirm-submission-delete"]': 'deleteSubmission',
        'input [data-form-editor-form]': 'renderPreview',
        'submit [data-form-editor-form]': 'saveDraft',
        'click [data-action="publish-form"]': 'publishForm',
        'click [data-action="archive-form"]': 'archiveForm',
        'click [data-action="copy-embed"]': 'copyEmbed',
        'click [data-action="preview-form"]': 'previewForm',
    };

    setup() {
        this.setPageTitle('Forms');
        this.workspace = null;
        this.currentForm = null;
        this.selectedFields = [];
        this.conditionalRules = [];
        this.tableManager = new WorkspaceTable(this);
        this.tableSort = {forms: {key: 'modifiedAt', direction: 'desc'}, submissions: {key: 'createdAt', direction: 'desc'}};
        this.submissionOffset = 0;
        this.submissionLimit = 100;
        this.submissionLoading = false;
        this.submissionTotal = 0;
        this.pendingSubmissionDelete = null;
    }

    afterRender() {
        super.afterRender();
        document.body.classList.add('nexa-forms-page');
        this.once('remove', () => {
            document.body.classList.remove('nexa-forms-page');
            clearTimeout(this.submissionSearchTimer);
            this.submissionScroll?.removeEventListener('scroll', this.submissionScrollHandler);
        });
        this.load();
    }

    async load() {
        this.setState('loading');
        try {
            this.workspace = await Espo.Ajax.getRequest('Nexa/forms/workspace');
            this.submissionOffset = (this.workspace.recentSubmissions || []).length;
            this.submissionTotal = Number(this.workspace.submissionTotal || 0);
            this.renderForms();
            this.renderSubmissions();
            this.setState('ready');
            this.bindSubmissionScroll();
        } catch (error) {
            this.setState('error');
            Espo.Ui.error(error?.message || 'Forms could not be loaded.');
        }
    }

    setState(state) {
        this.element.querySelectorAll('[data-form-state]').forEach(node => {
            node.hidden = node.dataset.formState !== state;
            node.style.display = node.hidden ? 'none' : '';
        });
    }

    switchTab(event) {
        const tab = event.currentTarget.dataset.tab;
        this.element.querySelectorAll('[data-action="switch-tab"]').forEach(button => button.classList.toggle('is-active', button.dataset.tab === tab));
        this.element.querySelectorAll('[data-form-tab]').forEach(panel => {
            panel.hidden = panel.dataset.formTab !== tab;
            panel.style.display = panel.hidden ? 'none' : '';
        });
    }

    renderForms() {
        if (!this.workspace) return;
        const query = (this.element.querySelector('[data-form-search]')?.value || '').trim().toLowerCase();
        const status = this.element.querySelector('[data-form-status]')?.value || '';
        const forms = (this.workspace.forms || []).filter(item => {
            const config = item.configuration || {};
            return (!status || item.status === status) && (!query || `${config.name || ''} ${config.description || ''}`.toLowerCase().includes(query));
        });
        const sort = this.tableSort.forms;
        const values = {
            form: item => item.configuration?.name || '', status: item => item.status || '', views: item => Number(item.viewCount || 0),
            submissions: item => Number(item.submissionCount || 0), conversion: item => Number(item.conversionRate || 0),
            records: item => Number(item.createdRecordCount || 0), modifiedAt: item => item.modifiedAt || item.createdAt || '',
        };
        forms.sort((left, right) => this.compare(values[sort.key]?.(left), values[sort.key]?.(right), sort.direction));
        const body = this.element.querySelector('[data-form-list]');
        body.innerHTML = forms.map(item => {
            const config = item.configuration || {};
            const statusLabel = item.status === 'published' && item.hasUnpublishedChanges ? 'Published + draft' : this.title(item.status);
            return `<tr>
                <td data-column="form"><button type="button" class="nexa-form-name" data-action="edit-form" data-id="${this.escape(item.id)}"><span class="fas fa-file-alt" aria-hidden="true"></span><span><strong>${this.escape(config.name || 'Untitled form')}</strong><small>${this.escape(config.description || `${(config.fieldList || []).length} CRM fields`)}</small></span></button></td>
                <td data-column="status"><span class="nexa-form-status is-${this.escape(item.status)}">${this.escape(statusLabel)}</span>${item.version ? `<small class="nexa-version">Version ${item.version}</small>` : ''}</td>
                <td data-column="views">${Number(item.viewCount || 0).toLocaleString()}</td>
                <td data-column="submissions">${Number(item.submissionCount || 0).toLocaleString()}</td>
                <td data-column="conversion">${Number(item.conversionRate || 0).toLocaleString(undefined, {maximumFractionDigits: 1})}%</td>
                <td data-column="records">${Number(item.createdRecordCount || 0).toLocaleString()}</td>
                <td data-column="modifiedAt">${this.date(item.modifiedAt || item.createdAt)}</td>
                <td data-column="actions"><div class="nexa-row-actions"><button class="btn btn-icon" type="button" data-action="edit-form" data-id="${this.escape(item.id)}" title="Edit"><span class="fas fa-pen"></span></button>${item.formUrl ? `<button class="btn btn-icon" type="button" data-action="preview-form" data-id="${this.escape(item.id)}" title="Open published form"><span class="fas fa-external-link-alt"></span></button><button class="btn btn-icon" type="button" data-action="copy-embed" data-id="${this.escape(item.id)}" title="Copy embed code"><span class="fas fa-code"></span></button>` : ''}<button class="btn btn-icon is-danger" type="button" data-action="archive-form" data-id="${this.escape(item.id)}" title="Archive"><span class="fas fa-archive"></span></button></div></td>
            </tr>`;
        }).join('');
        this.element.querySelector('[data-form-count]').textContent = `${forms.length.toLocaleString()} ${forms.length === 1 ? 'form' : 'forms'}`;
        const empty = this.element.querySelector('[data-form-empty]');
        empty.hidden = forms.length !== 0;
        empty.style.display = empty.hidden ? 'none' : '';
        this.element.querySelector('.nexa-form-table-wrap').hidden = forms.length === 0;
        this.enhanceTable('forms');
    }

    renderSubmissions() {
        if (!this.workspace) return;
        const list = this.workspace.recentSubmissions || [];
        this.element.querySelector('[data-submission-list]').innerHTML = list.map(item => `<tr><td data-column="submittedBy"><strong>${this.escape(item.name || item.email || 'Anonymous visitor')}</strong><small>${this.escape(item.email || 'No email supplied')}</small></td><td data-column="formName">${this.escape(item.formName)}</td><td data-column="result">${item.targetId ? `<a href="#${this.escape(item.targetType)}/view/${this.escape(item.targetId)}">${this.escape(item.isCreated ? `Created ${item.targetType}` : `Matched ${item.targetType}`)}</a>` : '<span class="text-muted">Not created</span>'}</td><td data-column="createdAt">${this.date(item.createdAt, true)}</td><td data-column="actions"><button class="btn btn-icon is-danger" type="button" data-action="delete-submission" data-id="${this.escape(item.id)}" title="Delete submission temporarily"><span class="fas fa-trash-alt"></span></button></td></tr>`).join('') || '<tr><td colspan="5" class="nexa-table-empty">No matching submissions.</td></tr>';
        const loaded = list.length;
        this.element.querySelector('[data-submission-count]').textContent = `${this.submissionTotal.toLocaleString()} ${this.submissionTotal === 1 ? 'submission' : 'submissions'}${loaded < this.submissionTotal ? `, ${loaded.toLocaleString()} loaded` : ''}`;
        this.enhanceTable('submissions');
    }

    enhanceTable(name) {
        const table = this.element.querySelector(`[data-workspace-table="${name}"]`);
        this.tableManager.enhance(table, `forms:${name}`);
        const sort = this.tableSort[name];
        table?.querySelectorAll('[data-table-sort]').forEach(button => {
            const active = button.dataset.tableSort === sort.key;
            button.classList.toggle('is-sorted', active);
            button.dataset.direction = active ? sort.direction : '';
            button.closest('th')?.setAttribute('aria-sort', active ? (sort.direction === 'asc' ? 'ascending' : 'descending') : 'none');
        });
    }

    sortTable(event) {
        const table = event.currentTarget.closest('[data-workspace-table]')?.dataset.workspaceTable;
        if (!table) return;
        const key = event.currentTarget.dataset.tableSort;
        const state = this.tableSort[table];
        state.direction = state.key === key && state.direction === 'asc' ? 'desc' : 'asc';
        state.key = key;
        if (table === 'forms') this.renderForms();
        else this.reloadSubmissions();
    }

    compare(left, right, direction) {
        const factor = direction === 'asc' ? 1 : -1;
        if (typeof left === 'number' && typeof right === 'number') return (left - right) * factor;
        return String(left ?? '').localeCompare(String(right ?? ''), undefined, {numeric: true, sensitivity: 'base'}) * factor;
    }

    queueSubmissionSearch() {
        clearTimeout(this.submissionSearchTimer);
        this.submissionSearchTimer = setTimeout(() => this.reloadSubmissions(), 250);
    }

    async reloadSubmissions() {
        if (this.submissionLoading) return;
        this.submissionLoading = true;
        try {
            const response = await this.fetchSubmissionPage(0);
            this.workspace.recentSubmissions = response.list || [];
            this.submissionOffset = this.workspace.recentSubmissions.length;
            this.submissionTotal = Number(response.total || 0);
            this.renderSubmissions();
            const scroll = this.element.querySelector('[data-table-scroll="submissions"]');
            if (scroll) scroll.scrollTop = 0;
        } catch (error) {
            Espo.Ui.error(error?.message || 'Submissions could not be loaded.');
        } finally {
            this.submissionLoading = false;
        }
    }

    fetchSubmissionPage(offset) {
        const sort = this.tableSort.submissions;
        return Espo.Ajax.getRequest('Nexa/forms/submissions', {
            offset,
            limit: this.submissionLimit,
            q: (this.element.querySelector('[data-submission-search]')?.value || '').trim(),
            orderBy: sort.key,
            direction: sort.direction,
        });
    }

    bindSubmissionScroll() {
        const scroll = this.element.querySelector('[data-table-scroll="submissions"]');
        if (!scroll || scroll === this.submissionScroll) return;
        this.submissionScroll?.removeEventListener('scroll', this.submissionScrollHandler);
        this.submissionScroll = scroll;
        this.submissionScrollHandler = () => {
            if (scroll.scrollHeight - scroll.scrollTop - scroll.clientHeight < 180) this.loadMoreSubmissions();
        };
        scroll.addEventListener('scroll', this.submissionScrollHandler, {passive: true});
    }

    async loadMoreSubmissions() {
        if (this.submissionLoading || this.submissionOffset >= this.submissionTotal) return;
        this.submissionLoading = true;
        try {
            const response = await this.fetchSubmissionPage(this.submissionOffset);
            this.workspace.recentSubmissions.push(...(response.list || []));
            this.submissionOffset = this.workspace.recentSubmissions.length;
            this.submissionTotal = Number(response.total || this.submissionTotal);
            this.renderSubmissions();
        } catch (error) {
            Espo.Ui.error(error?.message || 'More submissions could not be loaded.');
        } finally {
            this.submissionLoading = false;
        }
    }

    openSubmissionDelete(event) {
        const item = (this.workspace.recentSubmissions || []).find(row => row.id === event.currentTarget.dataset.id);
        if (!item) return;
        this.pendingSubmissionDelete = item;
        const dialog = this.element.querySelector('[data-submission-delete-dialog]');
        dialog.querySelector('[data-submission-delete-copy]').textContent = `The submission from ${item.name || item.email || 'this visitor'} will be moved to the recycle bin.`;
        const warning = dialog.querySelector('[data-submission-lead-warning]');
        warning.hidden = !(item.isCreated && item.targetType === 'Lead');
        warning.style.display = warning.hidden ? 'none' : '';
        dialog.hidden = false;
        dialog.style.display = 'grid';
        dialog.querySelector('[data-action="confirm-submission-delete"]').focus();
    }

    closeSubmissionDelete() {
        const dialog = this.element.querySelector('[data-submission-delete-dialog]');
        dialog.hidden = true;
        dialog.style.display = 'none';
        this.pendingSubmissionDelete = null;
    }

    async deleteSubmission() {
        if (!this.pendingSubmissionDelete) return;
        const id = this.pendingSubmissionDelete.id;
        Espo.Ui.notifyWait();
        try {
            const result = await Espo.Ajax.deleteRequest(`Nexa/forms/submissions/${encodeURIComponent(id)}`);
            this.closeSubmissionDelete();
            await this.reloadSubmissions();
            this.workspace.forms = (await Espo.Ajax.getRequest('Nexa/forms/workspace')).forms;
            this.renderForms();
            Espo.Ui.success(result.deletedLead ? 'Submission and created Lead moved to the recycle bin' : 'Submission moved to the recycle bin');
        } catch (error) {
            Espo.Ui.error(error?.message || 'The submission could not be deleted.');
        } finally {
            Espo.Ui.notify(false);
        }
    }

    newForm() {
        this.openEditor(null);
    }

    editForm(event) {
        const form = (this.workspace.forms || []).find(item => item.id === event.currentTarget.dataset.id);
        if (form) this.openEditor(form);
    }

    openEditor(form) {
        this.currentForm = form;
        const config = form?.configuration || this.defaultConfiguration();
        this.selectedFields = (config.fieldList || []).map(name => ({name, required: (config.requiredFields || []).includes(name), mapsTo: config.fieldMapping?.[name] || name}));
        this.conditionalRules = (config.conditionalRules || []).map(rule => ({...rule}));
        const editor = this.element.querySelector('[data-form-editor]');
        const node = this.element.querySelector('[data-form-editor-form]');
        node.reset();
        ['name', 'description', 'title', 'intro', 'leadSource', 'successMessage', 'redirectUrl', 'consentLabel'].forEach(name => { node.elements[name].value = config[name] || ''; });
        node.elements.redirectDelaySeconds.value = config.redirectDelaySeconds || 4;
        node.elements.targetListId.innerHTML = this.selectOptions(this.workspace.targetLists, 'No audience list');
        node.elements.targetTeamId.innerHTML = this.selectOptions(this.workspace.teams, 'No team assignment');
        node.elements.assignedUserId.innerHTML = this.selectOptions(this.workspace.users, 'Keep automatic ownership');
        node.elements.consentPurposeId.innerHTML = this.selectOptions(this.workspace.purposes, 'No consent field');
        node.elements.formTheme.innerHTML = this.selectOptions(this.workspace.themes, 'Use system theme');
        node.elements.lifecycleStage.innerHTML = this.selectOptions(this.workspace.lifecycleStages, 'Do not change lifecycle');
        node.elements.marketingStatus.innerHTML = this.selectOptions(this.workspace.marketingStatuses, 'Do not change marketing status');
        node.elements.targetListId.value = config.targetListId || '';
        node.elements.targetTeamId.value = config.targetTeamId || '';
        node.elements.assignedUserId.value = config.assignedUserId || '';
        node.elements.consentPurposeId.value = config.consentPurposeId || '';
        node.elements.formTheme.value = config.formTheme || 'Espo';
        node.elements.consentChannel.value = config.consentChannel || '';
        node.elements.lifecycleStage.value = config.lifecycleStage || '';
        node.elements.marketingStatus.value = config.marketingStatus || '';
        node.elements.subscribeToTargetList.checked = Boolean(config.subscribeToTargetList);
        node.elements.duplicateCheck.checked = config.duplicateCheck !== false;
        node.elements.captcha.checked = Boolean(config.captcha);
        node.elements.progressiveProfiling.checked = Boolean(config.progressiveProfiling);
        node.elements.frameAncestors.value = (config.frameAncestors || []).join('\n');
        this.element.querySelector('[data-editor-title]').textContent = form ? config.name : 'New form';
        this.element.querySelector('[data-editor-status]').textContent = form ? `${this.title(form.status)}${form.version ? `, version ${form.version}` : ''}` : 'Draft not saved';
        editor.hidden = false;
        editor.style.display = 'flex';
        document.body.classList.add('nexa-modal-open');
        this.renderFieldCatalog();
        this.renderSelectedFields();
        this.renderConditionalRules();
        node.elements.name.focus();
    }

    closeEditor() {
        const editor = this.element.querySelector('[data-form-editor]');
        editor.hidden = true;
        editor.style.display = 'none';
        document.body.classList.remove('nexa-modal-open');
        this.currentForm = null;
    }

    defaultConfiguration() {
        return {name: '', title: '', formTheme: 'Espo', leadSource: 'Web Site', successMessage: 'Thanks. Your information has been received.', redirectDelaySeconds: 4, duplicateCheck: true, fieldList: ['firstName', 'lastName', 'emailAddress'], requiredFields: ['lastName', 'emailAddress'], fieldMapping: {firstName: 'firstName', lastName: 'lastName', emailAddress: 'emailAddress'}, conditionalRules: [], frameAncestors: []};
    }

    renderFieldCatalog() {
        const query = (this.element.querySelector('[data-field-search]')?.value || '').trim().toLowerCase();
        const selected = new Set(this.selectedFields.map(item => item.name));
        const fields = (this.workspace?.fieldCatalog || []).filter(item => !selected.has(item.name) && (!query || `${item.label} ${item.name} ${item.type}`.toLowerCase().includes(query)));
        this.element.querySelector('[data-field-catalog]').innerHTML = fields.map(item => `<button type="button" data-action="add-field" data-name="${this.escape(item.name)}"><span class="fas fa-plus" aria-hidden="true"></span><span><strong>${this.escape(item.label)}</strong><small>${this.escape(this.title(item.type))}</small></span></button>`).join('') || '<p>No available fields match.</p>';
    }

    renderSelectedFields() {
        const catalog = new Map((this.workspace?.fieldCatalog || []).map(item => [item.name, item]));
        this.element.querySelector('[data-selected-fields]').innerHTML = this.selectedFields.map((field, index) => {
            const item = catalog.get(field.name) || {label: field.name, type: 'text'};
            const compatible = (this.workspace.fieldCatalog || []).filter(candidate => candidate.type === item.type);
            const mapping = compatible.map(candidate => `<option value="${this.escape(candidate.name)}" ${candidate.name === (field.mapsTo || field.name) ? 'selected' : ''}>${this.escape(candidate.label)}</option>`).join('');
            return `<article data-field-name="${this.escape(field.name)}"><span class="fas fa-grip-vertical" aria-hidden="true"></span><div><strong>${this.escape(item.label)}</strong><label class="nexa-field-map"><span>Save answer to</span><select class="form-control input-sm" data-action="change-mapping" data-name="${this.escape(field.name)}">${mapping}</select></label></div><label><input type="checkbox" data-action="toggle-required" data-name="${this.escape(field.name)}" ${field.required || item.required ? 'checked' : ''} ${item.required ? 'disabled' : ''}>Required</label><div><button class="btn btn-icon" type="button" data-action="move-field-up" data-name="${this.escape(field.name)}" ${index === 0 ? 'disabled' : ''} title="Move up"><span class="fas fa-arrow-up"></span></button><button class="btn btn-icon" type="button" data-action="move-field-down" data-name="${this.escape(field.name)}" ${index === this.selectedFields.length - 1 ? 'disabled' : ''} title="Move down"><span class="fas fa-arrow-down"></span></button><button class="btn btn-icon is-danger" type="button" data-action="remove-field" data-name="${this.escape(field.name)}" title="Remove"><span class="fas fa-times"></span></button></div></article>`;
        }).join('') || '<div class="nexa-field-empty"><span class="fas fa-arrow-left"></span><p>Add CRM fields from the property list.</p></div>';
        this.renderPreview();
    }

    addField(event) { const name = event.currentTarget.dataset.name; this.selectedFields.push({name, required: false, mapsTo: name}); this.renderFieldCatalog(); this.renderSelectedFields(); this.renderConditionalRules(); }
    removeField(event) { const name = event.currentTarget.dataset.name; this.selectedFields = this.selectedFields.filter(item => item.name !== name); this.conditionalRules = this.conditionalRules.filter(rule => rule.sourceField !== name && rule.targetField !== name); this.renderFieldCatalog(); this.renderSelectedFields(); this.renderConditionalRules(); }
    moveFieldUp(event) { this.moveField(event.currentTarget.dataset.name, -1); }
    moveFieldDown(event) { this.moveField(event.currentTarget.dataset.name, 1); }
    moveField(name, delta) { const index = this.selectedFields.findIndex(item => item.name === name); const target = index + delta; if (index < 0 || target < 0 || target >= this.selectedFields.length) return; [this.selectedFields[index], this.selectedFields[target]] = [this.selectedFields[target], this.selectedFields[index]]; this.renderSelectedFields(); }
    toggleRequired(event) { const field = this.selectedFields.find(item => item.name === event.currentTarget.dataset.name); if (field) field.required = event.currentTarget.checked; this.renderPreview(); }
    changeMapping(event) { const field = this.selectedFields.find(item => item.name === event.currentTarget.dataset.name); if (field) field.mapsTo = event.currentTarget.value; }

    readConditionalRules() {
        return [...this.element.querySelectorAll('[data-condition-rule]')].map(node => ({
            sourceField: node.querySelector('[data-condition-source]').value,
            operator: node.querySelector('[data-condition-operator]').value,
            value: node.querySelector('[data-condition-value]').value.trim(),
            targetField: node.querySelector('[data-condition-target]').value,
        }));
    }

    renderConditionalRules() {
        const host = this.element.querySelector('[data-conditional-rules]');
        if (!host) return;
        const fields = this.selectedFields.map(field => ({id: field.name, name: (this.workspace.fieldCatalog || []).find(item => item.name === field.name)?.label || field.name}));
        const options = (selected, excluded = '') => fields.filter(field => field.id !== excluded).map(field => `<option value="${this.escape(field.id)}" ${field.id === selected ? 'selected' : ''}>${this.escape(field.name)}</option>`).join('');
        host.innerHTML = this.conditionalRules.map((rule, index) => `<article data-condition-rule data-index="${index}"><label><span>When</span><select class="form-control input-sm" data-condition-source>${options(rule.sourceField, rule.targetField)}</select></label><label><span>Condition</span><select class="form-control input-sm" data-condition-operator><option value="equals" ${rule.operator === 'equals' ? 'selected' : ''}>equals</option><option value="notEquals" ${rule.operator === 'notEquals' ? 'selected' : ''}>does not equal</option><option value="contains" ${rule.operator === 'contains' ? 'selected' : ''}>contains</option><option value="isEmpty" ${rule.operator === 'isEmpty' ? 'selected' : ''}>is empty</option><option value="isNotEmpty" ${rule.operator === 'isNotEmpty' ? 'selected' : ''}>is not empty</option></select></label><label><span>Value</span><input class="form-control input-sm" data-condition-value value="${this.escape(rule.value || '')}" ${['isEmpty', 'isNotEmpty'].includes(rule.operator) ? 'disabled' : ''}></label><label><span>Show</span><select class="form-control input-sm" data-condition-target>${options(rule.targetField, rule.sourceField)}</select></label><button class="btn btn-icon is-danger" type="button" data-action="remove-condition" data-index="${index}" title="Remove condition"><span class="fas fa-times"></span></button></article>`).join('') || '<p class="nexa-condition-empty">No conditional fields. Every selected field is shown.</p>';
    }

    addCondition() {
        if (this.selectedFields.length < 2) { Espo.Ui.error('Add at least two form fields before creating a condition.'); return; }
        this.conditionalRules = this.readConditionalRules();
        this.conditionalRules.push({sourceField: this.selectedFields[0].name, operator: 'equals', value: '', targetField: this.selectedFields[1].name});
        this.renderConditionalRules();
    }

    removeCondition(event) {
        this.conditionalRules = this.readConditionalRules().filter((rule, index) => index !== Number(event.currentTarget.dataset.index));
        this.renderConditionalRules();
    }

    renderPreview() {
        const preview = this.element.querySelector('[data-form-preview]');
        if (!preview || !this.workspace) return;
        const catalog = new Map(this.workspace.fieldCatalog.map(item => [item.name, item]));
        preview.innerHTML = this.selectedFields.map(field => { const item = catalog.get(field.name) || {label: field.name, type: 'text'}; return `<label><span>${this.escape(item.label)}${field.required || item.required ? ' *' : ''}</span><input type="text" disabled></label>`; }).join('') || '<p>Your selected fields will appear here.</p>';
    }

    payload() {
        const form = this.element.querySelector('[data-form-editor-form]');
        return {
            name: form.elements.name.value.trim(), description: form.elements.description.value.trim(), title: form.elements.title.value.trim(), formTheme: form.elements.formTheme.value, intro: form.elements.intro.value.trim(),
            leadSource: form.elements.leadSource.value.trim(), targetListId: form.elements.targetListId.value, targetTeamId: form.elements.targetTeamId.value, assignedUserId: form.elements.assignedUserId.value,
            subscribeToTargetList: form.elements.subscribeToTargetList.checked, duplicateCheck: form.elements.duplicateCheck.checked, captcha: form.elements.captcha.checked,
            progressiveProfiling: form.elements.progressiveProfiling.checked, consentPurposeId: form.elements.consentPurposeId.value, consentChannel: form.elements.consentChannel.value,
            consentLabel: form.elements.consentLabel.value.trim(), successMessage: form.elements.successMessage.value.trim(), redirectUrl: form.elements.redirectUrl.value.trim(), redirectDelaySeconds: Number(form.elements.redirectDelaySeconds.value || 4),
            lifecycleStage: form.elements.lifecycleStage.value, marketingStatus: form.elements.marketingStatus.value,
            frameAncestors: form.elements.frameAncestors.value.split(/\r?\n/).map(value => value.trim()).filter(Boolean), fields: this.selectedFields,
            fieldMapping: Object.fromEntries(this.selectedFields.map(field => [field.name, field.mapsTo || field.name])), conditionalRules: this.readConditionalRules(),
        };
    }

    validatePayload(payload) {
        this.element.querySelectorAll('[data-form-error]').forEach(node => node.textContent = '');
        if (!payload.name) { this.element.querySelector('[data-form-error="name"]').textContent = 'Enter a form name.'; return false; }
        if (!payload.fields.length) { Espo.Ui.error('Add at least one field to the form.'); return false; }
        if (payload.consentPurposeId && !payload.consentChannel) { Espo.Ui.error('Select the communication channel covered by the consent statement.'); return false; }
        if (payload.marketingStatus === 'Marketing' && !payload.consentPurposeId) { Espo.Ui.error('Select a consent purpose before marking submissions as marketing contacts.'); return false; }
        if (payload.conditionalRules.some(rule => !rule.sourceField || !rule.targetField || rule.sourceField === rule.targetField)) { Espo.Ui.error('Choose two different fields for each condition.'); return false; }
        return true;
    }

    async persist() {
        const payload = this.payload();
        if (!this.validatePayload(payload)) return null;
        Espo.Ui.notifyWait();
        try {
            const response = this.currentForm ? await Espo.Ajax.putRequest(`Nexa/forms/${this.currentForm.id}`, payload) : await Espo.Ajax.postRequest('Nexa/forms', payload);
            this.workspace = response;
            this.currentForm = (response.forms || []).find(item => item.id === response.savedId) || this.currentForm;
            this.renderForms(); this.renderSubmissions();
            return response.savedId || this.currentForm?.id;
        } catch (error) {
            Espo.Ui.error(error?.message || 'The form could not be saved.');
            return null;
        } finally { Espo.Ui.notify(false); }
    }

    async saveDraft(event) {
        event.preventDefault();
        const id = await this.persist();
        if (!id) return;
        Espo.Ui.success('Draft saved');
        this.closeEditor();
    }

    async publishForm() {
        const id = await this.persist();
        if (!id) return;
        Espo.Ui.notifyWait();
        try {
            this.workspace = await Espo.Ajax.postRequest(`Nexa/forms/${id}/publish`, {});
            this.renderForms(); this.renderSubmissions(); this.closeEditor(); Espo.Ui.success('Form published');
        } catch (error) { Espo.Ui.error(error?.message || 'The form could not be published.'); }
        finally { Espo.Ui.notify(false); }
    }

    async archiveForm(event) {
        const id = event.currentTarget.dataset.id;
        if (!window.confirm('Archive this form? Its public form will stop accepting submissions.')) return;
        Espo.Ui.notifyWait();
        try { this.workspace = await Espo.Ajax.postRequest(`Nexa/forms/${id}/archive`, {}); this.renderForms(); Espo.Ui.success('Form archived'); }
        catch (error) { Espo.Ui.error(error?.message || 'The form could not be archived.'); }
        finally { Espo.Ui.notify(false); }
    }

    previewForm(event) { const form = this.findForm(event.currentTarget.dataset.id); if (form?.formUrl) window.open(form.formUrl, '_blank', 'noopener'); }
    async copyEmbed(event) { const form = this.findForm(event.currentTarget.dataset.id); if (!form?.formUrl) return; const code = `<iframe src="${form.formUrl}" title="${form.configuration?.name || 'Contact form'}" loading="lazy" style="width:100%;min-height:640px;border:0"></iframe>`; try { await navigator.clipboard.writeText(code); Espo.Ui.success('Embed code copied'); } catch { Espo.Ui.error('The embed code could not be copied.'); } }
    findForm(id) { return (this.workspace?.forms || []).find(item => item.id === id); }
    selectOptions(items, empty) { return `<option value="">${this.escape(empty)}</option>` + (items || []).map(item => `<option value="${this.escape(item.id)}">${this.escape(item.name)}</option>`).join(''); }
    date(value, time = false) { if (!value) return 'Not recorded'; const date = new Date(value.replace(' ', 'T') + 'Z'); if (Number.isNaN(date.getTime())) return this.escape(value); return new Intl.DateTimeFormat(undefined, {year: 'numeric', month: 'short', day: 'numeric', ...(time ? {hour: 'numeric', minute: '2-digit'} : {})}).format(date); }
    title(value) { return String(value || '').replace(/([a-z])([A-Z])/g, '$1 $2').replace(/_/g, ' ').replace(/\b\w/g, char => char.toUpperCase()); }
    escape(value) { const node = document.createElement('div'); node.textContent = String(value ?? ''); return node.innerHTML; }
});
