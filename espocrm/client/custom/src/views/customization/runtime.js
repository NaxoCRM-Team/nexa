define('custom:views/customization/runtime', ['view'], Dep => class extends Dep {
    template = 'custom:customization/runtime';

    events = {
        'input [data-record-search]': 'queueSearch',
        'click [data-action="create-record"]': 'openCreate',
        'click [data-action="back-to-list"]': 'showList',
        'click [data-action="cancel-form"]': 'cancelForm',
        'submit [data-record-form]': 'saveRecord',
        'click [data-open-record]': 'openRecord',
        'click [data-action="edit-record"]': 'openEdit',
        'click [data-action="delete-record"]': 'deleteRecord',
        'click [data-page]': 'changePage',
        'click [data-manage-relationship]': 'openAssociation',
        'input [data-candidate-search]': 'queueCandidateSearch',
        'click [data-connect-candidate]': 'connectCandidate',
        'click [data-unlink-record]': 'unlinkRecord',
        'click [data-action="close-associations"]': 'closeAssociations',
    };

    setup() {
        this.entityKey = this.options.entityKey;
        this.recordId = this.options.recordId || null;
        this.requestedMode = this.options.mode || null;
        this.offset = 0;
        this.limit = 25;
        this.search = '';
        this.searchTimer = null;
        this.candidateTimer = null;
        this.dataSet = null;
        this.currentRecord = null;
        this.activeRelationshipId = null;
    }

    afterRender() {
        super.afterRender();
        this.load();
    }

    async load() {
        this.element.classList.add('is-loading');
        try {
            const definitions = await Espo.Ajax.getRequest('Nexa/customization/definitions', {entityType: this.entityKey});
            const entity = (definitions.entities || []).find(item => item.entity_key === this.entityKey);
            if (!entity) throw new Error('This custom object is not available in the current workspace.');
            this.dataSet = {...definitions, entity};
            this.setPageTitle(entity.plural_label);
            this.element.querySelector('[data-object-label]').textContent = entity.plural_label;
            this.element.querySelector('[data-object-description]').textContent = entity.description || `Manage ${entity.plural_label.toLowerCase()} for this workspace.`;
            this.element.querySelector('[data-create-label]').textContent = `New ${entity.label}`;
            if (this.recordId) await this.loadRecord(this.recordId, this.requestedMode === 'edit');
            else await this.loadRecords();
        } catch (error) {
            this.element.querySelector('[data-runtime-screen="list"]').innerHTML = `<div class="nexa-empty-state"><span class="fas fa-exclamation-circle" aria-hidden="true"></span><h3>Workspace unavailable</h3><p></p></div>`;
            this.element.querySelector('.nexa-empty-state p').textContent = error?.message || 'The custom object could not be opened.';
            Espo.Ui.error(error?.message || 'The custom object could not be opened.');
        } finally {
            this.element.classList.remove('is-loading');
        }
    }

    fieldsFor(context) {
        const definitions = (this.dataSet?.fields || []).filter(field => field.is_enabled !== false);
        const layout = (this.dataSet?.layouts || []).find(item => item.layout_context === context)?.layout;
        if (!Array.isArray(layout) || !layout.length) return definitions;
        const byKey = new Map(definitions.map(field => [field.field_key, field]));
        const fields = layout.map(key => byKey.get(key)).filter(Boolean);

        // A tenant layout may hide optional properties, but it must never produce
        // a create/edit form that cannot satisfy the server's required-field contract.
        if (['create', 'edit'].includes(context)) {
            definitions
                .filter(field => field.is_required && !fields.some(item => item.field_key === field.field_key))
                .forEach(field => fields.push(field));
        }

        return fields;
    }

    async loadRecords() {
        const result = await Espo.Ajax.getRequest(`Nexa/customization/entities/${encodeURIComponent(this.entityKey)}/records`, {offset: this.offset, limit: this.limit, q: this.search});
        this.renderRecords(result);
        this.activateScreen('list');
    }

    renderRecords(result) {
        const host = this.element.querySelector('[data-record-list]');
        const records = result.records || [];
        host.replaceChildren();
        this.element.querySelector('[data-record-total]').textContent = `${result.total} ${result.total === 1 ? this.dataSet.entity.label.toLowerCase() : this.dataSet.entity.plural_label.toLowerCase()}`;
        if (!records.length) {
            host.innerHTML = `<div class="nexa-empty-state"><span class="far fa-folder-open" aria-hidden="true"></span><h3>${this.search ? 'No matching records' : `No ${this.escape(this.dataSet.entity.plural_label.toLowerCase())} yet`}</h3><p>${this.search ? 'Try another search.' : `Create the first ${this.escape(this.dataSet.entity.label.toLowerCase())} for this workspace.`}</p></div>`;
            this.renderPagination(result.total);
            return;
        }
        const listFields = this.fieldsFor('list').slice(0, 4);
        const table = document.createElement('div');
        table.className = 'nexa-runtime-table';
        const header = document.createElement('div');
        header.className = 'nexa-runtime-table-row nexa-runtime-table-head';
        header.innerHTML = '<span>Name</span>' + listFields.map(field => `<span>${this.escape(field.label)}</span>`).join('') + '<span>Updated</span>';
        table.style.setProperty('--runtime-data-columns', String(listFields.length + 1));
        table.append(header);
        records.forEach(record => {
            const row = document.createElement('button');
            row.type = 'button';
            row.className = 'nexa-runtime-table-row';
            row.dataset.openRecord = record.id;
            row.innerHTML = `<span class="nexa-runtime-name"><span class="nexa-runtime-list-avatar">${this.escape(record.display_name.charAt(0).toUpperCase() || 'R')}</span><strong>${this.escape(record.display_name)}</strong></span>` + listFields.map(field => `<span>${this.escape(this.displayValue(record.values?.[field.field_key], field))}</span>`).join('') + `<span>${this.escape(this.formatDate(record.updated_at))}</span>`;
            table.append(row);
        });
        host.append(table);
        this.renderPagination(result.total);
    }

    renderPagination(total) {
        const host = this.element.querySelector('[data-pagination]');
        host.replaceChildren();
        const pages = Math.ceil(total / this.limit);
        if (pages <= 1) return;
        const current = Math.floor(this.offset / this.limit) + 1;
        const previous = document.createElement('button');
        previous.type = 'button'; previous.className = 'btn btn-default'; previous.dataset.page = String(current - 1); previous.disabled = current === 1; previous.innerHTML = '<span class="fas fa-chevron-left" aria-hidden="true"></span><span>Previous</span>';
        const status = document.createElement('span'); status.textContent = `Page ${current} of ${pages}`;
        const next = document.createElement('button');
        next.type = 'button'; next.className = 'btn btn-default'; next.dataset.page = String(current + 1); next.disabled = current === pages; next.innerHTML = '<span>Next</span><span class="fas fa-chevron-right" aria-hidden="true"></span>';
        host.append(previous, status, next);
    }

    queueSearch(event) {
        window.clearTimeout(this.searchTimer);
        this.searchTimer = window.setTimeout(async () => {
            this.search = event.currentTarget.value.trim();
            this.offset = 0;
            try { await this.loadRecords(); } catch (error) { Espo.Ui.error(error?.message || 'Records could not be searched.'); }
        }, 250);
    }

    changePage(event) {
        const page = Number(event.currentTarget.dataset.page);
        if (!Number.isFinite(page) || page < 1) return;
        this.offset = (page - 1) * this.limit;
        this.loadRecords();
    }

    openCreate() {
        this.currentRecord = null;
        this.renderForm('create');
    }

    openEdit() {
        if (this.currentRecord) this.renderForm('edit', this.currentRecord);
    }

    renderForm(context, snapshot = null) {
        const form = this.element.querySelector('[data-record-form]');
        form.reset();
        form.dataset.recordId = snapshot?.record?.id || '';
        this.element.querySelector('[data-form-eyebrow]').textContent = context === 'edit' ? 'Edit record' : 'New record';
        this.element.querySelector('[data-form-title]').textContent = context === 'edit' ? snapshot.record.display_name : `Create ${this.dataSet.entity.label.toLowerCase()}`;
        const host = this.element.querySelector('[data-record-fields]');
        host.replaceChildren();
        host.append(this.formField({field_key: 'displayName', label: `${this.dataSet.entity.label} name`, data_type: 'text', is_required: true}, snapshot?.record?.display_name || ''));
        this.fieldsFor(context).forEach(definition => host.append(this.formField(definition, snapshot?.values?.[definition.field_key])));
        this.activateScreen('form');
        host.querySelector('input, select, textarea')?.focus();
    }

    formField(definition, value) {
        const label = document.createElement('label');
        label.className = definition.data_type === 'long_text' ? 'nexa-runtime-field is-wide' : 'nexa-runtime-field';
        const title = document.createElement('span');
        title.innerHTML = `${this.escape(definition.label)}${definition.is_required ? ' <strong aria-label="required">*</strong>' : ''}`;
        const input = this.createInput(definition, value);
        input.name = definition.field_key === 'displayName' ? 'displayName' : `value:${definition.field_key}`;
        input.required = Boolean(definition.is_required);
        if (definition.description) input.title = definition.description;
        label.append(title, input);
        return label;
    }

    createInput(definition, value) {
        if (definition.data_type === 'long_text') {
            const input = document.createElement('textarea'); input.rows = 5; input.value = value || ''; return input;
        }
        if (['single_select', 'multi_select'].includes(definition.data_type)) {
            const input = document.createElement('select'); input.multiple = definition.data_type === 'multi_select';
            if (!input.multiple) input.append(new Option('Select...', ''));
            (definition.options || []).forEach(option => { const item = new Option(option, option); item.selected = input.multiple ? (value || []).includes(option) : value === option; input.append(item); });
            return input;
        }
        const input = document.createElement('input');
        input.type = ({number: 'number', currency: 'number', date: 'date', datetime: 'datetime-local', email: 'email', url: 'url', phone: 'tel'}[definition.data_type] || 'text');
        if (definition.data_type === 'boolean') { input.type = 'checkbox'; input.checked = Boolean(value); }
        else input.value = value ?? '';
        return input;
    }

    async saveRecord(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const submit = form.querySelector('[type="submit"]');
        const values = {};
        [...form.elements].forEach(input => {
            if (!input.name?.startsWith('value:')) return;
            const key = input.name.slice(6);
            values[key] = input.type === 'checkbox' ? input.checked : input.multiple ? [...input.selectedOptions].map(option => option.value) : input.value;
        });
        submit.disabled = true;
        try {
            const saved = await Espo.Ajax.postRequest(`Nexa/customization/entities/${encodeURIComponent(this.entityKey)}/records`, {id: form.dataset.recordId || undefined, displayName: form.elements.displayName.value, values});
            Espo.Ui.success(`${this.dataSet.entity.label} saved.`);
            await this.loadRecord(saved.id);
        } catch (error) {
            Espo.Ui.error(error?.message || 'The record could not be saved.');
        } finally { submit.disabled = false; }
    }

    openRecord(event) { this.loadRecord(event.currentTarget.dataset.openRecord); }

    async loadRecord(id, edit = false) {
        const snapshot = await Espo.Ajax.getRequest(`Nexa/customization/entities/${encodeURIComponent(this.entityKey)}/records/${encodeURIComponent(id)}`);
        this.currentRecord = snapshot;
        this.recordId = id;
        if (edit) { this.renderForm('edit', snapshot); return; }
        this.renderDetail(snapshot);
    }

    renderDetail(snapshot) {
        const record = snapshot.record;
        this.element.querySelector('[data-record-avatar]').textContent = record.display_name.charAt(0).toUpperCase() || 'R';
        this.element.querySelector('[data-record-kind]').textContent = this.dataSet.entity.label;
        this.element.querySelector('[data-record-name]').textContent = record.display_name;
        this.element.querySelector('[data-record-updated]').textContent = `Updated ${this.formatDate(record.updated_at)}`;
        const values = this.element.querySelector('[data-detail-values]');
        values.replaceChildren();
        this.fieldsFor('detail').forEach(definition => {
            const wrapper = document.createElement('div');
            wrapper.innerHTML = `<dt>${this.escape(definition.label)}</dt><dd>${this.escape(this.displayValue(snapshot.values?.[definition.field_key], definition))}</dd>`;
            values.append(wrapper);
        });
        if (!values.children.length) values.innerHTML = '<p class="nexa-runtime-muted">No additional properties have been configured.</p>';
        this.renderRelationshipCards(snapshot.relationships || []);
        this.activateScreen('detail');
    }

    renderRelationshipCards(relationships) {
        const host = this.element.querySelector('[data-relationship-list]');
        host.replaceChildren();
        const relevant = relationships.filter(item => item.source_entity_type === this.entityKey || item.target_entity_type === this.entityKey);
        if (!relevant.length) { host.innerHTML = '<div class="nexa-runtime-muted">No associations configured.</div>'; return; }
        relevant.forEach(relationship => {
            const card = document.createElement('button'); card.type = 'button'; card.className = 'nexa-runtime-relationship-card'; card.dataset.manageRelationship = relationship.id;
            const label = relationship.source_entity_type === this.entityKey ? relationship.label : relationship.inverse_label;
            card.innerHTML = `<span class="fas fa-link" aria-hidden="true"></span><span><strong>${this.escape(label)}</strong><small>View and connect records</small></span><span class="fas fa-chevron-right" aria-hidden="true"></span>`;
            host.append(card);
        });
    }

    async openAssociation(event) {
        this.activeRelationshipId = event.currentTarget.dataset.manageRelationship;
        this.element.querySelector('[data-dialog="associations"]').hidden = false;
        document.body.classList.add('modal-open');
        await this.loadAssociation();
    }

    async loadAssociation(query = '') {
        const host = this.element.querySelector('[data-association-workspace]');
        host.innerHTML = '<p class="nexa-loading-copy">Loading association...</p>';
        try {
            const data = await Espo.Ajax.getRequest(`Nexa/customization/relationships/${encodeURIComponent(this.activeRelationshipId)}/${encodeURIComponent(this.entityKey)}/${encodeURIComponent(this.currentRecord.record.id)}`, query ? {q: query} : undefined);
            this.element.querySelector('[data-association-title]').textContent = data.relationship.label;
            host.replaceChildren();
            const linked = document.createElement('section'); linked.className = 'nexa-runtime-linked'; linked.innerHTML = '<h3>Connected records</h3>';
            if (!data.links.length) linked.insertAdjacentHTML('beforeend', '<p class="nexa-runtime-muted">No records connected yet.</p>');
            data.links.forEach(item => linked.insertAdjacentHTML('beforeend', `<div><span class="fas fa-link" aria-hidden="true"></span><strong>${this.escape(item.label)}</strong><button type="button" class="btn btn-default btn-sm" data-unlink-record="${this.escape(item.id)}">Remove</button></div>`));
            host.append(linked);
            if (!data.relationship.canLinkMore) return;
            const picker = document.createElement('section'); picker.className = 'nexa-runtime-picker'; picker.innerHTML = `<h3>Connect ${this.escape(data.relationship.relatedEntityLabel)}</h3><label><span class="fas fa-search" aria-hidden="true"></span><span class="sr-only">Search available records</span><input type="search" data-candidate-search placeholder="Search ${this.escape(data.relationship.relatedEntityLabel.toLowerCase())}" value="${this.escape(query)}"></label><div data-candidate-list></div>`;
            const candidates = picker.querySelector('[data-candidate-list]');
            if (!data.candidates.length) candidates.innerHTML = '<p class="nexa-runtime-muted">No available records found.</p>';
            data.candidates.forEach(item => candidates.insertAdjacentHTML('beforeend', `<button type="button" data-connect-candidate="${this.escape(item.id)}" data-current-is-source="${String(data.relationship.currentIsSource)}"><span class="nexa-runtime-list-avatar">${this.escape(item.label.charAt(0).toUpperCase() || 'R')}</span><strong>${this.escape(item.label)}</strong><span class="fas fa-plus" aria-hidden="true"></span></button>`));
            host.append(picker);
            picker.querySelector('input')?.focus();
        } catch (error) { host.innerHTML = `<div class="nexa-empty-state"><p>${this.escape(error?.message || 'The association could not be loaded.')}</p></div>`; }
    }

    queueCandidateSearch(event) { window.clearTimeout(this.candidateTimer); const value = event.currentTarget.value.trim(); this.candidateTimer = window.setTimeout(() => this.loadAssociation(value), 250); }

    async connectCandidate(event) {
        const button = event.currentTarget;
        const currentIsSource = button.dataset.currentIsSource === 'true';
        const currentId = this.currentRecord.record.id;
        const candidateId = button.dataset.connectCandidate;
        button.disabled = true;
        try {
            await Espo.Ajax.postRequest('Nexa/customization/relationships/link', {relationshipDefinitionId: this.activeRelationshipId, sourceEntityId: currentIsSource ? currentId : candidateId, targetEntityId: currentIsSource ? candidateId : currentId});
            Espo.Ui.success('Record connected.'); await this.loadAssociation();
        } catch (error) { button.disabled = false; Espo.Ui.error(error?.message || 'The record could not be connected.'); }
    }

    async unlinkRecord(event) {
        if (!window.confirm('Remove this association? The records will not be deleted.')) return;
        try { await Espo.Ajax.deleteRequest(`Nexa/customization/relationships/link/${encodeURIComponent(event.currentTarget.dataset.unlinkRecord)}`); Espo.Ui.success('Association removed.'); await this.loadAssociation(); }
        catch (error) { Espo.Ui.error(error?.message || 'The association could not be removed.'); }
    }

    closeAssociations() { this.element.querySelector('[data-dialog="associations"]').hidden = true; document.body.classList.remove('modal-open'); }

    async deleteRecord() {
        if (!this.currentRecord || !window.confirm(`Delete ${this.currentRecord.record.display_name}? This record can no longer be used.`)) return;
        try { await Espo.Ajax.deleteRequest(`Nexa/customization/entities/${encodeURIComponent(this.entityKey)}/records/${encodeURIComponent(this.currentRecord.record.id)}`); Espo.Ui.success(`${this.dataSet.entity.label} deleted.`); this.recordId = null; this.currentRecord = null; this.offset = 0; await this.loadRecords(); }
        catch (error) { Espo.Ui.error(error?.message || 'The record could not be deleted.'); }
    }

    cancelForm() { this.currentRecord ? this.renderDetail(this.currentRecord) : this.showList(); }
    showList() { this.recordId = null; this.currentRecord = null; this.loadRecords(); }

    activateScreen(name) {
        this.element.querySelectorAll('[data-runtime-screen]').forEach(screen => { screen.hidden = screen.dataset.runtimeScreen !== name; });
        this.element.querySelector('[data-action="back-to-list"]').hidden = name === 'list';
        this.element.querySelector('[data-action="create-record"]').hidden = name !== 'list';
    }

    displayValue(value, definition) {
        if (value === null || value === undefined || value === '' || (Array.isArray(value) && !value.length)) return 'Not recorded';
        if (definition.data_type === 'boolean') return value ? 'Yes' : 'No';
        if (Array.isArray(value)) return value.join(', ');
        return String(value);
    }
    formatDate(value) { if (!value) return 'Not recorded'; const parsed = new Date(String(value).replace(' ', 'T') + (String(value).includes('Z') ? '' : 'Z')); return Number.isNaN(parsed.valueOf()) ? value : new Intl.DateTimeFormat(undefined, {dateStyle: 'medium', timeStyle: 'short'}).format(parsed); }
    escape(value) { const node = document.createElement('span'); node.textContent = String(value ?? ''); return node.innerHTML; }
});
