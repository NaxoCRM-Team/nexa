define('custom:views/customization/admin', ['view'], Dep => class extends Dep {
    template = 'custom:customization/admin';

    events = {
        'click [data-action="refresh"]': 'load',
        'click [data-action="select-object"]': 'selectObject',
        'click [data-action="back-to-objects"]': 'backToObjects',
        'input [data-object-search]': 'filterObjects',
        'input [data-property-search]': 'filterProperties',
        'input [data-form="field"] [name="label"]': 'validatePropertyIdentity',
        'input [data-form="field"] [name="fieldKey"]': 'validatePropertyIdentity',
        'click [data-object-tab]': 'selectObjectTab',
        'click [data-action="open-property-dialog"]': 'openPropertyDialog',
        'click [data-action="open-object-dialog"]': 'openObjectDialog',
        'click [data-action="open-association-dialog"]': 'openAssociationDialog',
        'click [data-action="close-dialog"]': 'closeDialog',
        'input [data-key-source]': 'updateGeneratedKey',
        'input [data-generated-key]': 'markKeyAsEdited',
        'change [data-form="field"] [name="dataType"]': 'togglePropertyOptions',
        'change [data-form="relationship"] [name="targetEntityType"]': 'updateAssociationLanguage',
        'submit [data-form="field"]': 'saveField',
        'submit [data-form="entity"]': 'saveEntity',
        'submit [data-form="relationship"]': 'saveRelationship',
        'change [data-layout-context]': 'renderLayoutBuilder',
        'change [data-layout-visible]': 'renderLayoutPreviewFromRows',
        'click [data-action="move-up"]': 'moveLayoutField',
        'click [data-action="move-down"]': 'moveLayoutField',
        'click [data-action="save-layout"]': 'saveLayout',
        'click [data-action="toggle-property"]': 'toggleProperty',
        'click [data-action="select-object-icon"]': 'selectObjectIcon',
        'click [data-action="archive"]': 'archive',
        'click [data-action="toggle-record-form"]': 'toggleRecordForm',
        'submit [data-form="custom-record"]': 'saveCustomRecord',
        'click [data-action="manage-record-associations"]': 'openRecordAssociations',
        'input [data-association-search]': 'searchAssociationCandidates',
        'click [data-action="connect-record"]': 'connectRecord',
        'click [data-action="unlink-record"]': 'unlinkRecord',
    };

    setup() {
        this.setPageTitle('Objects & Properties');
        this.dataSet = null;
        this.selectedEntityType = null;
        this.activeTab = 'properties';
        this.associationSearchTimers = new Map();
        this.escapeHandler = event => {
            if (event.key === 'Escape') this.closeDialog();
        };
    }

    afterRender() {
        super.afterRender();
        document.addEventListener('keydown', this.escapeHandler);
        this.once('remove', () => document.removeEventListener('keydown', this.escapeHandler));
        this.load();
    }

    async load() {
        this.element.classList.add('is-loading');
        try {
            this.dataSet = await Espo.Ajax.getRequest('Nexa/customization/definitions');
            this.populateFieldTypes();
            this.renderObjectList();
            if (this.selectedEntityType && this.entityOptions().some(item => item.key === this.selectedEntityType)) {
                this.renderObjectWorkspace();
            }
        } catch (error) {
            Espo.Ui.error(error?.message || 'Objects and properties could not be loaded.');
        } finally {
            this.element.classList.remove('is-loading');
        }
    }

    entityOptions() {
        const native = {
            Contact: {label: 'Contacts', singular: 'Contact', description: 'People, customers and individual relationships.', icon: 'fas fa-address-card'},
            Account: {label: 'Accounts', singular: 'Account', description: 'Companies, organizations and business relationships.', icon: 'fas fa-building'},
        };
        return [
            ...(this.dataSet?.nativeEntityTypes || []).map(key => ({key, ...native[key], native: true})),
            ...(this.dataSet?.entities || []).map(item => ({
                key: item.entity_key,
                label: item.plural_label,
                singular: item.label,
                description: item.description || `Custom ${item.plural_label.toLowerCase()} managed by this workspace.`,
                icon: item.icon_class || 'fas fa-cubes',
                native: false,
            })),
        ];
    }

    currentEntity() {
        return this.entityOptions().find(item => item.key === this.selectedEntityType) || null;
    }

    renderObjectList() {
        const host = this.element.querySelector('[data-object-list]');
        if (!host) return;
        host.replaceChildren();
        this.entityOptions().forEach(entity => {
            const fieldCount = this.fieldsFor(entity.key).length;
            const associationCount = this.relationshipsFor(entity.key).length;
            const card = document.createElement('button');
            card.type = 'button';
            card.className = 'nexa-object-card';
            card.dataset.action = 'select-object';
            card.dataset.entityType = entity.key;
            card.innerHTML = '<span class="nexa-object-card-icon" aria-hidden="true"></span><span class="nexa-object-card-copy"><span class="nexa-object-card-title"><strong></strong><small></small></span><span class="nexa-object-card-description"></span><span class="nexa-object-card-meta"></span></span><span class="fas fa-chevron-right nexa-object-card-arrow" aria-hidden="true"></span>';
            card.querySelector('.nexa-object-card-icon').innerHTML = `<span class="${entity.icon}"></span>`;
            card.querySelector('strong').textContent = entity.label;
            card.querySelector('small').textContent = entity.native ? 'Nexa object' : 'Custom object';
            card.querySelector('.nexa-object-card-description').textContent = entity.description;
            card.querySelector('.nexa-object-card-meta').textContent = `${fieldCount} additional ${fieldCount === 1 ? 'property' : 'properties'} | ${associationCount} ${associationCount === 1 ? 'association' : 'associations'}`;
            host.append(card);
        });
    }

    filterObjects(event) {
        const query = event.currentTarget.value.trim().toLowerCase();
        this.element.querySelectorAll('.nexa-object-card').forEach(card => {
            card.hidden = query !== '' && !card.textContent.toLowerCase().includes(query);
        });
    }

    selectObject(event) {
        this.selectedEntityType = event.currentTarget.dataset.entityType;
        this.activeTab = 'properties';
        this.renderObjectWorkspace();
    }

    backToObjects() {
        this.selectedEntityType = null;
        this.element.querySelector('[data-screen="workspace"]').hidden = true;
        this.element.querySelector('[data-screen="overview"]').hidden = false;
        this.element.querySelector('[data-object-search]')?.focus();
    }

    renderObjectWorkspace() {
        const entity = this.currentEntity();
        if (!entity) return;
        this.element.querySelector('[data-screen="overview"]').hidden = true;
        this.element.querySelector('[data-screen="workspace"]').hidden = false;
        this.element.querySelector('[data-object-title]').textContent = entity.label;
        this.element.querySelector('[data-object-kind]').textContent = entity.native ? 'Nexa object' : 'Custom object';
        this.element.querySelector('[data-object-description]').textContent = entity.description;
        this.element.querySelector('[data-object-icon]').innerHTML = `<span class="${entity.icon}"></span>`;
        this.element.querySelectorAll('[data-custom-only]').forEach(element => element.hidden = entity.native);
        if (entity.native && this.activeTab === 'records') this.activeTab = 'properties';
        this.activateObjectTab(this.activeTab);
        this.renderProperties();
        this.renderLayoutBuilder();
        this.renderAssociations();
    }

    selectObjectTab(event) {
        this.activeTab = event.currentTarget.dataset.objectTab;
        this.activateObjectTab(this.activeTab);
        if (this.activeTab === 'records') this.loadRecords();
    }

    activateObjectTab(name) {
        this.element.querySelectorAll('[data-object-tab]').forEach(tab => {
            const active = tab.dataset.objectTab === name;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', String(active));
        });
        this.element.querySelectorAll('[data-object-panel]').forEach(panel => {
            panel.hidden = panel.dataset.objectPanel !== name;
        });
    }

    fieldsFor(entityType) {
        return (this.dataSet?.fields || []).filter(item => item.entity_type === entityType);
    }

    enabledFieldsFor(entityType) {
        return this.fieldsFor(entityType).filter(item => item.is_enabled !== false);
    }

    standardFieldsFor(entityType) {
        return (this.dataSet?.standardFields || []).filter(item => item.entity_type === entityType);
    }

    propertyCatalogueFor(entityType) {
        return [
            ...this.standardFieldsFor(entityType).map(item => ({...item, source: 'standard'})),
            ...this.fieldsFor(entityType).map(item => ({...item, source: 'custom'})),
        ];
    }

    relationshipsFor(entityType) {
        return (this.dataSet?.relationships || []).filter(item => item.source_entity_type === entityType || item.target_entity_type === entityType);
    }

    renderProperties(query = '') {
        const host = this.element.querySelector('[data-property-list]');
        if (!host || !this.selectedEntityType) return;
        const catalogue = this.propertyCatalogueFor(this.selectedEntityType);
        const fields = catalogue.filter(field => !query || `${field.label} ${field.field_key} ${field.data_type} ${field.source}`.toLowerCase().includes(query));
        const standardCount = this.standardFieldsFor(this.selectedEntityType).length;
        const customCount = this.fieldsFor(this.selectedEntityType).length;
        const enabledCount = catalogue.filter(field => field.is_enabled !== false).length;
        this.element.querySelector('[data-property-count]').textContent = `${enabledCount} enabled | ${standardCount} standard | ${customCount} custom`;
        host.replaceChildren();
        if (!fields.length) {
            host.innerHTML = query ? '<div class="nexa-empty-state"><span class="fas fa-search" aria-hidden="true"></span><h4>No matching properties</h4><p>Try a different property name or internal name.</p></div>' : '<div class="nexa-empty-state"><span class="fas fa-list-alt" aria-hidden="true"></span><h4>No properties yet</h4><p>Add the first property needed by this object.</p><button type="button" class="btn btn-primary" data-action="open-property-dialog">Add the first property</button></div>';
            return;
        }
        const table = document.createElement('div');
        table.className = 'nexa-property-table';
        fields.forEach(field => {
            const row = document.createElement('article');
            row.dataset.propertySearch = `${field.label} ${field.field_key} ${field.data_type}`.toLowerCase();
            row.classList.toggle('is-standard', field.source === 'standard');
            row.classList.toggle('is-disabled', field.is_enabled === false);
            row.innerHTML = '<div><strong></strong><p></p><small class="nexa-property-source"></small></div><span class="nexa-property-type"></span><span class="nexa-property-placement"></span><span class="nexa-property-action"></span>';
            row.querySelector('strong').textContent = field.label;
            row.querySelector('p').textContent = field.description || 'No description';
            row.querySelector('.nexa-property-source').textContent = `${field.source === 'standard' ? 'Standard property' : 'Tenant property'} | ${field.field_key}`;
            row.querySelector('.nexa-property-type').textContent = this.typeLabel(field.data_type);
            row.querySelector('.nexa-property-placement').textContent = field.source === 'standard' ? this.propertyCapabilities(field) : `${this.propertyPlacement(field.field_key)} | ${this.propertyCapabilities(field)}`;
            const action = row.querySelector('.nexa-property-action');
            const toggle = document.createElement('button');
            const enabled = field.is_enabled !== false;
            toggle.type = 'button';
            toggle.className = 'nexa-property-toggle';
            toggle.dataset.action = 'toggle-property';
            toggle.dataset.fieldKey = field.field_key;
            toggle.setAttribute('role', 'switch');
            toggle.setAttribute('aria-checked', String(enabled));
            toggle.setAttribute('aria-label', `${enabled ? 'Disable' : 'Enable'} ${field.label}`);
            toggle.title = field.is_protected ? 'Required core property' : `${enabled ? 'Disable' : 'Enable'} property`;
            toggle.disabled = Boolean(field.is_protected);
            toggle.innerHTML = '<span aria-hidden="true"></span>';
            action.append(toggle);
            if (field.source === 'custom') {
                const button = document.createElement('button');
                button.type = 'button'; button.className = 'btn btn-default btn-sm nexa-property-archive'; button.dataset.action = 'archive'; button.dataset.kind = 'field'; button.dataset.id = field.id; button.title = 'Archive property';
                button.innerHTML = '<span class="fas fa-archive" aria-hidden="true"></span><span>Archive</span>';
                action.append(button);
            }
            table.append(row);
        });
        host.append(table);
    }

    filterProperties(event) {
        this.renderProperties(event.currentTarget.value.trim().toLowerCase());
    }

    propertyPlacement(fieldKey) {
        const labels = {create: 'Create', edit: 'Edit', detail: 'Details', list: 'List', search: 'Search'};
        const contexts = (this.dataSet?.layouts || []).filter(layout => layout.entity_type === this.selectedEntityType && layout.layout.includes(fieldKey)).map(layout => labels[layout.layout_context]);
        return contexts.length ? contexts.join(', ') : 'Uses default placement';
    }

    propertyCapabilities(field) {
        const values = [];
        if (field.is_searchable) values.push('Searchable');
        if (field.is_filterable) values.push('Filterable');
        if (field.is_required) values.push('Required');
        if (field.is_unique) values.push('Unique');
        return values.length ? values.join(', ') : 'Display only';
    }

    populateFieldTypes() {
        const select = this.element.querySelector('[data-form="field"] [name="dataType"]');
        if (!select) return;
        select.replaceChildren();
        (this.dataSet?.fieldTypes || []).filter(type => type !== 'relationship').forEach(type => select.append(new Option(this.typeLabel(type), type)));
    }

    openPropertyDialog() {
        const entity = this.currentEntity();
        if (!entity) return;
        const form = this.element.querySelector('[data-form="field"]');
        form.reset();
        form.elements.fieldKey.dataset.userEdited = '';
        this.element.querySelector('[data-property-object-name]').textContent = entity.label;
        this.togglePropertyOptions({currentTarget: form.elements.dataType});
        this.validatePropertyIdentity({currentTarget: form.elements.label});
        this.openDialog('property', form.elements.label);
    }

    openObjectDialog() {
        const form = this.element.querySelector('[data-form="entity"]');
        form.reset();
        form.elements.iconClass.value = 'fas fa-cubes';
        this.renderObjectIconSelection('fas fa-cubes');
        form.elements.entityKey.dataset.userEdited = '';
        form.elements.pluralLabel.dataset.userEdited = '';
        this.openDialog('object', form.elements.label);
    }

    selectObjectIcon() {
        const form = this.element.querySelector('[data-form="entity"]');
        this.createView('objectIconSelector', 'views/admin/entity-manager/modals/select-icon', {}, view => {
            view.render();
            this.listenToOnce(view, 'select', value => {
                const iconClass = value || '';
                form.elements.iconClass.value = iconClass;
                this.renderObjectIconSelection(iconClass);
                view.close();
            });
        });
    }

    renderObjectIconSelection(iconClass) {
        const preview = this.element.querySelector('[data-selected-object-icon]');
        const label = this.element.querySelector('[data-selected-object-icon-label]');
        preview.className = iconClass;
        preview.hidden = !iconClass;
        label.textContent = iconClass ? iconClass.replace(/^(fas|far|fab|fal) fa-/, '').replaceAll('-', ' ') : 'None';
    }

    openAssociationDialog() {
        const source = this.currentEntity();
        if (!source) return;
        const form = this.element.querySelector('[data-form="relationship"]');
        form.reset();
        form.elements.sourceEntityType.value = source.key;
        form.elements.relationshipKey.dataset.userEdited = '';
        const target = form.elements.targetEntityType;
        target.replaceChildren(new Option('Select an object', ''));
        this.entityOptions().filter(item => item.key !== source.key).forEach(item => target.append(new Option(item.label, item.key)));
        this.element.querySelector('[data-association-source-name]').textContent = source.label;
        this.element.querySelector('[data-source-singular]').textContent = source.singular;
        this.element.querySelector('[data-source-plural]').textContent = source.label;
        this.element.querySelector('[data-target-singular]').textContent = 'related record';
        this.element.querySelector('[data-target-plural]').textContent = 'related records';
        this.openDialog('association', target);
    }

    openDialog(name, focusTarget) {
        const dialog = this.element.querySelector(`[data-dialog="${name}"]`);
        dialog.hidden = false;
        document.body.classList.add('nexa-dialog-open');
        this.lastDialogTrigger = document.activeElement;
        setTimeout(() => focusTarget?.focus(), 0);
    }

    closeDialog(event) {
        const dialog = event?.currentTarget?.closest('[data-dialog]') || this.element.querySelector('[data-dialog]:not([hidden])');
        if (!dialog) return;
        dialog.hidden = true;
        document.body.classList.remove('nexa-dialog-open');
        this.lastDialogTrigger?.focus?.();
    }

    updateGeneratedKey(event) {
        const form = event.currentTarget.closest('form');
        const keyInput = form.querySelector('[data-generated-key]');
        if (keyInput && !keyInput.dataset.userEdited) keyInput.value = this.keyFrom(event.currentTarget.value);
        if (form.dataset.form === 'entity') {
            const plural = form.elements.pluralLabel;
            if (!plural.dataset.userEdited) plural.value = this.pluralize(event.currentTarget.value);
        }
        if (form.dataset.form === 'field') this.validatePropertyIdentity({currentTarget: event.currentTarget});
    }

    markKeyAsEdited(event) {
        event.currentTarget.dataset.userEdited = event.currentTarget.value ? 'true' : '';
        if (event.currentTarget.closest('form')?.dataset.form === 'field') this.validatePropertyIdentity(event);
    }

    validatePropertyIdentity(event) {
        const form = event.currentTarget.closest('form');
        if (!form || form.dataset.form !== 'field') return;
        const label = String(form.elements.label.value || '').trim().toLowerCase().replace(/[^a-z0-9]+/g, '');
        const key = String(form.elements.fieldKey.value || '').trim().toLowerCase();
        const conflict = this.propertyCatalogueFor(this.selectedEntityType).find(field =>
            field.field_key.toLowerCase() === key || String(field.label || '').trim().toLowerCase().replace(/[^a-z0-9]+/g, '') === label
        );
        const message = form.querySelector('[data-property-conflict]');
        const submit = form.querySelector('[type="submit"]');
        message.hidden = !conflict;
        message.textContent = conflict ? `${conflict.label} already exists as a ${conflict.source === 'standard' ? 'standard' : 'tenant'} property. Use the existing property instead.` : '';
        submit.disabled = Boolean(conflict);
    }

    keyFrom(value) {
        let key = String(value || '').trim().toLowerCase().normalize('NFKD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 64);
        if (!/^[a-z]/.test(key)) key = `field_${key}`;
        if (key.length < 2) key = `${key || 'custom'}_field`;
        return key.slice(0, 64);
    }

    pluralize(value) {
        const word = String(value || '').trim();
        if (!word) return '';
        if (/[^aeiou]y$/i.test(word)) return `${word.slice(0, -1)}ies`;
        if (/(s|x|z|ch|sh)$/i.test(word)) return `${word}es`;
        return `${word}s`;
    }

    togglePropertyOptions(event) {
        const form = event.currentTarget.closest('form');
        const options = form.querySelector('.nexa-property-options');
        const visible = ['single_select', 'multi_select'].includes(event.currentTarget.value);
        options.hidden = !visible;
        options.querySelector('textarea').required = visible;
    }

    async saveField(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const data = this.formData(form);
        data.entityType = this.selectedEntityType;
        data.options = String(data.options || '').split(/\r?\n/).map(value => value.trim()).filter(Boolean);
        data.isRequired = form.elements.isRequired.checked;
        data.isUnique = form.elements.isUnique.checked;
        data.isSearchable = form.elements.isSearchable.checked;
        data.isFilterable = form.elements.isFilterable.checked;
        data.position = this.fieldsFor(this.selectedEntityType).length;
        const contexts = [...form.querySelectorAll('[name="showOn"]:checked')].map(input => input.value);
        try {
            const created = await Espo.Ajax.postRequest('Nexa/customization/definitions/field', data);
            this.dataSet = await Espo.Ajax.getRequest('Nexa/customization/definitions');
            for (const context of ['create', 'edit', 'detail']) {
                await this.addFieldToLayout(created.fieldKey, context, contexts.includes(context));
            }
            this.closeDialog();
            Espo.Ui.success('Property created and added to the selected screens.');
            await this.load();
        } catch (error) {
            Espo.Ui.error(error?.message || 'The property could not be created.');
        }
    }

    async addFieldToLayout(fieldKey, context, include) {
        const existing = (this.dataSet.layouts || []).find(item => item.entity_type === this.selectedEntityType && item.layout_context === context);
        const layout = existing
            ? [...existing.layout]
            : this.fieldsFor(this.selectedEntityType).map(field => field.field_key).filter(key => key !== fieldKey);
        const currentIndex = layout.indexOf(fieldKey);
        if (include && currentIndex === -1) layout.push(fieldKey);
        if (!include && currentIndex !== -1) layout.splice(currentIndex, 1);
        await Espo.Ajax.postRequest('Nexa/customization/definitions/layout', {entityType: this.selectedEntityType, layoutContext: context, layout});
    }

    async saveEntity(event) {
        event.preventDefault();
        const form = event.currentTarget;
        try {
            const created = await Espo.Ajax.postRequest('Nexa/customization/definitions/entity', this.formData(form));
            this.closeDialog();
            await this.load();
            document.dispatchEvent(new CustomEvent('nexa:custom-objects-changed'));
            this.selectedEntityType = created.entityKey;
            this.activeTab = 'properties';
            this.renderObjectWorkspace();
            Espo.Ui.success('Custom object created. Add its first property next.');
            this.openPropertyDialog();
        } catch (error) {
            Espo.Ui.error(error?.message || 'The custom object could not be created.');
        }
    }

    async toggleProperty(event) {
        const button = event.currentTarget;
        const isEnabled = button.getAttribute('aria-checked') !== 'true';
        button.disabled = true;
        try {
            await Espo.Ajax.postRequest('Nexa/customization/definitions/propertyPreference', {
                entityType: this.selectedEntityType,
                fieldKey: button.dataset.fieldKey,
                isEnabled,
            });
            document.dispatchEvent(new CustomEvent('nexa:property-visibility-changed', {
                detail: {entityType: this.selectedEntityType, fieldKey: button.dataset.fieldKey, isEnabled},
            }));
            Espo.Ui.success(`Property ${isEnabled ? 'enabled' : 'disabled'}. Existing data was preserved.`);
            await this.load();
        } catch (error) {
            button.disabled = false;
            Espo.Ui.error(error?.message || 'The property setting could not be updated.');
        }
    }

    updateAssociationLanguage(event) {
        const target = this.entityOptions().find(item => item.key === event.currentTarget.value);
        if (!target) return;
        const source = this.currentEntity();
        this.element.querySelector('[data-target-singular]').textContent = target.singular;
        this.element.querySelector('[data-target-plural]').textContent = target.label;
        const form = event.currentTarget.closest('form');
        form.elements.label.value = target.label;
        form.elements.inverseLabel.value = source.label;
        if (!form.elements.relationshipKey.dataset.userEdited) form.elements.relationshipKey.value = this.keyFrom(`${source.key} ${target.key}`);
    }

    async saveRelationship(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const data = this.formData(form);
        const sourceMany = data.sourceMultiplicity === 'many';
        const targetMany = data.targetMultiplicity === 'many';
        data.cardinality = sourceMany && targetMany ? 'many_to_many' : sourceMany ? 'one_to_many' : targetMany ? 'many_to_one' : 'one_to_one';
        delete data.sourceMultiplicity;
        delete data.targetMultiplicity;
        try {
            await Espo.Ajax.postRequest('Nexa/customization/definitions/relationship', data);
            this.closeDialog();
            Espo.Ui.success('Association created.');
            await this.load();
            this.activateObjectTab('associations');
        } catch (error) {
            Espo.Ui.error(error?.message || 'The association could not be created.');
        }
    }

    renderAssociations() {
        const host = this.element.querySelector('[data-association-list]');
        if (!host || !this.selectedEntityType) return;
        const relationships = this.relationshipsFor(this.selectedEntityType);
        host.replaceChildren();
        if (!relationships.length) {
            host.innerHTML = '<div class="nexa-empty-state"><span class="fas fa-link" aria-hidden="true"></span><h4>No associations yet</h4><p>Connect this object when records need to reference another type of information.</p><button type="button" class="btn btn-primary" data-action="open-association-dialog">Create the first association</button></div>';
            return;
        }
        relationships.forEach(relationship => {
            const source = this.entityOptions().find(item => item.key === relationship.source_entity_type);
            const target = this.entityOptions().find(item => item.key === relationship.target_entity_type);
            const row = document.createElement('article');
            row.className = 'nexa-association-row';
            row.innerHTML = '<div class="nexa-association-icons"><span></span><i class="fas fa-long-arrow-alt-right"></i><span></span></div><div><strong></strong><p></p><small></small></div><button type="button" class="btn btn-default btn-sm" data-action="archive" data-kind="relationship" title="Archive association"><span class="far fa-archive"></span></button>';
            row.querySelectorAll('.nexa-association-icons span')[0].textContent = source?.singular?.charAt(0) || 'O';
            row.querySelectorAll('.nexa-association-icons span')[1].textContent = target?.singular?.charAt(0) || 'O';
            row.querySelector('strong').textContent = `${source?.label || relationship.source_entity_type} and ${target?.label || relationship.target_entity_type}`;
            row.querySelector('p').textContent = `${relationship.label} / ${relationship.inverse_label}`;
            row.querySelector('small').textContent = this.cardinalityLabel(relationship.cardinality, source, target);
            row.querySelector('[data-action="archive"]').dataset.id = relationship.id;
            host.append(row);
        });
    }

    cardinalityLabel(cardinality, source, target) {
        const names = {one_to_one: 'One to one', one_to_many: 'One to many', many_to_one: 'Many to one', many_to_many: 'Many to many'};
        return `${names[cardinality] || this.typeLabel(cardinality)} | ${source?.singular || 'Record'} to ${target?.singular || 'record'}`;
    }

    renderLayoutBuilder() {
        const host = this.element.querySelector('[data-layout-builder]');
        const preview = this.element.querySelector('[data-layout-preview]');
        if (!host || !preview || !this.selectedEntityType) return;
        const context = this.element.querySelector('[data-layout-context]').value;
        const fields = [...this.enabledFieldsFor(this.selectedEntityType)];
        const savedLayout = (this.dataSet.layouts || []).find(item => item.entity_type === this.selectedEntityType && item.layout_context === context);
        const layout = savedLayout?.layout || [];
        const order = new Map(layout.map((key, index) => [key, index]));
        fields.sort((a, b) => (order.get(a.field_key) ?? 9999) - (order.get(b.field_key) ?? 9999) || a.position - b.position);
        host.replaceChildren();
        preview.replaceChildren();
        this.element.querySelector('[data-preview-title]').textContent = `${this.currentEntity()?.singular || 'Record'} information`;
        if (!fields.length) {
            host.innerHTML = '<div class="nexa-empty-layout">Add a property before arranging this layout.</div>';
            preview.innerHTML = '<p>No additional properties to preview.</p>';
            return;
        }
        fields.forEach(field => {
            const row = document.createElement('div');
            row.className = 'nexa-layout-row';
            row.dataset.fieldKey = field.field_key;
            row.innerHTML = '<label class="nexa-layout-visibility"><input type="checkbox" data-layout-visible><span class="sr-only">Show property</span></label><span class="fas fa-grip-vertical" aria-hidden="true"></span><div><strong></strong><small></small></div><div class="nexa-layout-row-actions"><button type="button" data-action="move-up" aria-label="Move property up"><span class="fas fa-chevron-up"></span></button><button type="button" data-action="move-down" aria-label="Move property down"><span class="fas fa-chevron-down"></span></button></div>';
            row.querySelector('[data-layout-visible]').checked = savedLayout ? layout.includes(field.field_key) : true;
            row.querySelector('strong').textContent = field.label;
            row.querySelector('small').textContent = this.typeLabel(field.data_type);
            host.append(row);
        });
        this.renderLayoutPreviewFromRows();
    }

    moveLayoutField(event) {
        const row = event.currentTarget.closest('.nexa-layout-row');
        if (event.currentTarget.dataset.action === 'move-up' && row.previousElementSibling) row.parentNode.insertBefore(row, row.previousElementSibling);
        if (event.currentTarget.dataset.action === 'move-down' && row.nextElementSibling) row.parentNode.insertBefore(row.nextElementSibling, row);
        this.renderLayoutPreviewFromRows();
    }

    renderLayoutPreviewFromRows() {
        const preview = this.element.querySelector('[data-layout-preview]');
        preview.replaceChildren();
        this.element.querySelectorAll('.nexa-layout-row').forEach(row => {
            if (!row.querySelector('[data-layout-visible]')?.checked) return;
            const item = document.createElement('div');
            item.innerHTML = '<label></label><span>Not recorded</span>';
            item.querySelector('label').textContent = row.querySelector('strong').textContent;
            preview.append(item);
        });
    }

    async saveLayout() {
        const context = this.element.querySelector('[data-layout-context]').value;
        const layout = [...this.element.querySelectorAll('.nexa-layout-row')].filter(row => row.querySelector('[data-layout-visible]')?.checked).map(row => row.dataset.fieldKey);
        try {
            await Espo.Ajax.postRequest('Nexa/customization/definitions/layout', {entityType: this.selectedEntityType, layoutContext: context, layout});
            Espo.Ui.success('Record layout published.');
            await this.load();
            this.activateObjectTab('layout');
        } catch (error) {
            Espo.Ui.error(error?.message || 'The record layout could not be saved.');
        }
    }

    async archive(event) {
        const {kind, id} = event.currentTarget.dataset;
        if (!window.confirm(`Archive this ${kind}? Existing record values will be retained.`)) return;
        try {
            await Espo.Ajax.deleteRequest(`Nexa/customization/definitions/${kind}/${encodeURIComponent(id)}`);
            Espo.Ui.success(`${this.typeLabel(kind)} archived.`);
            await this.load();
        } catch (error) {
            Espo.Ui.error(error?.message || 'The item could not be archived.');
        }
    }

    async loadRecords() {
        const host = this.element.querySelector('[data-custom-records]');
        const entity = this.currentEntity();
        if (!host || !entity || entity.native) return;
        host.innerHTML = '<p class="nexa-loading-copy">Loading records...</p>';
        try {
            const result = await Espo.Ajax.getRequest(`Nexa/customization/entities/${encodeURIComponent(entity.key)}/records`);
            host.replaceChildren(this.recordForm(entity.key, this.enabledFieldsFor(entity.key)), this.recordList(result.records || [], entity.key));
            host.querySelector('[data-form="custom-record"]').hidden = true;
        } catch (error) {
            host.innerHTML = '<div class="nexa-empty-state"><p>Records could not be loaded.</p></div>';
        }
    }

    toggleRecordForm() {
        const form = this.element.querySelector('[data-form="custom-record"]');
        if (!form) return;
        form.hidden = !form.hidden;
        if (!form.hidden) form.elements.displayName.focus();
    }

    recordForm(key, definitions) {
        const form = document.createElement('form');
        form.dataset.form = 'custom-record';
        form.dataset.entityKey = key;
        form.className = 'nexa-custom-record-form';
        form.innerHTML = '<h4>New record</h4><label>Record name<input name="displayName" required maxlength="191"></label><div data-record-fields></div><footer><button type="submit" class="btn btn-primary">Create record</button></footer>';
        const fields = form.querySelector('[data-record-fields]');
        definitions.forEach(definition => {
            const label = document.createElement('label');
            label.textContent = definition.label;
            const input = this.recordInput(definition);
            input.name = `value:${definition.field_key}`;
            input.required = Boolean(definition.is_required);
            label.append(input);
            fields.append(label);
        });
        return form;
    }

    recordInput(definition) {
        if (definition.data_type === 'long_text') return document.createElement('textarea');
        if (['single_select', 'multi_select'].includes(definition.data_type)) {
            const select = document.createElement('select');
            select.multiple = definition.data_type === 'multi_select';
            if (!select.multiple) select.append(new Option('Select...', ''));
            (definition.options || []).forEach(option => select.append(new Option(option, option)));
            return select;
        }
        const input = document.createElement('input');
        input.type = ({number: 'number', currency: 'number', date: 'date', datetime: 'datetime-local', email: 'email', url: 'url', phone: 'tel'}[definition.data_type] || 'text');
        if (definition.data_type === 'boolean') input.type = 'checkbox';
        return input;
    }

    recordList(records, entityKey) {
        const host = document.createElement('div');
        host.className = 'nexa-custom-record-list';
        if (!records.length) {
            host.innerHTML = '<div class="nexa-empty-state"><span class="far fa-folder-open"></span><h4>No records yet</h4><p>Create a sample record to test this custom object.</p></div>';
            return host;
        }
        records.forEach(record => {
            const row = document.createElement('article');
            row.innerHTML = '<span class="fas fa-cubes" aria-hidden="true"></span><div><strong></strong><small></small></div><button type="button" class="btn btn-default btn-sm" data-action="manage-record-associations"><span class="fas fa-link" aria-hidden="true"></span><span>Manage associations</span></button>';
            row.querySelector('strong').textContent = record.display_name;
            row.querySelector('small').textContent = `Updated ${record.updated_at}`;
            const button = row.querySelector('[data-action="manage-record-associations"]');
            button.dataset.entityKey = entityKey;
            button.dataset.recordId = record.id;
            button.dataset.recordName = record.display_name;
            host.append(row);
        });
        return host;
    }

    async openRecordAssociations(event) {
        const button = event.currentTarget;
        this.associationRecord = {
            entityType: button.dataset.entityKey,
            entityId: button.dataset.recordId,
            name: button.dataset.recordName,
        };
        this.element.querySelector('[data-association-record-name]').textContent = this.associationRecord.name;
        const host = this.element.querySelector('[data-record-association-list]');
        const relationships = this.relationshipsFor(this.associationRecord.entityType);
        host.replaceChildren();
        if (!relationships.length) {
            host.innerHTML = '<div class="nexa-empty-state"><span class="fas fa-link" aria-hidden="true"></span><h4>No associations configured</h4><p>Create an association for this object before connecting records.</p></div>';
            this.openDialog('record-associations', host.querySelector('button'));
            return;
        }
        relationships.forEach(relationship => {
            const section = document.createElement('section');
            section.className = 'nexa-record-association-section';
            section.dataset.definitionId = relationship.id;
            section.innerHTML = '<div class="nexa-association-loading"><span class="fas fa-circle-notch fa-spin" aria-hidden="true"></span><span>Loading association...</span></div>';
            host.append(section);
        });
        this.openDialog('record-associations', host.querySelector('button, input'));
        await Promise.all(relationships.map(relationship => this.loadRelationshipSection(relationship.id)));
        host.querySelector('input')?.focus();
    }

    async loadRelationshipSection(definitionId, query = '') {
        const section = this.element.querySelector(`.nexa-record-association-section[data-definition-id="${CSS.escape(definitionId)}"]`);
        if (!section || !this.associationRecord) return;
        section.classList.add('is-loading');
        try {
            const {entityType, entityId} = this.associationRecord;
            const data = await Espo.Ajax.getRequest(
                `Nexa/customization/relationships/${encodeURIComponent(definitionId)}/${encodeURIComponent(entityType)}/${encodeURIComponent(entityId)}`,
                query ? {q: query} : undefined,
            );
            this.renderRelationshipSection(section, data, query);
        } catch (error) {
            section.innerHTML = '<div class="nexa-association-error"><span class="fas fa-exclamation-circle" aria-hidden="true"></span><span>Association records could not be loaded.</span></div>';
        } finally {
            section.classList.remove('is-loading');
        }
    }

    renderRelationshipSection(section, data, query) {
        const relationship = data.relationship;
        section.dataset.currentIsSource = String(Boolean(relationship.currentIsSource));
        section.replaceChildren();

        const header = document.createElement('header');
        header.innerHTML = '<div><h3></h3><p></p></div><span class="nexa-association-count"></span>';
        header.querySelector('h3').textContent = relationship.label;
        header.querySelector('p').textContent = `Connect this record to ${relationship.relatedEntityLabel.toLowerCase()}.`;
        header.querySelector('.nexa-association-count').textContent = `${data.links.length} connected`;
        section.append(header);

        const linked = document.createElement('div');
        linked.className = 'nexa-linked-records';
        if (!data.links.length) linked.innerHTML = '<p class="nexa-association-empty">No records connected yet.</p>';
        data.links.forEach(link => {
            const row = document.createElement('div');
            row.className = 'nexa-linked-record';
            row.innerHTML = '<span class="fas fa-link" aria-hidden="true"></span><strong></strong><button type="button" class="btn btn-default btn-sm" data-action="unlink-record" title="Remove association"><span class="fas fa-unlink" aria-hidden="true"></span><span>Remove</span></button>';
            row.querySelector('strong').textContent = link.label;
            row.querySelector('button').dataset.linkId = link.id;
            row.querySelector('button').dataset.definitionId = relationship.id;
            linked.append(row);
        });
        section.append(linked);

        if (!relationship.canLinkMore) {
            const limit = document.createElement('p');
            limit.className = 'nexa-association-limit';
            limit.textContent = 'This association allows only one connected record. Remove the current record to select another.';
            section.append(limit);
            return;
        }

        const picker = document.createElement('div');
        picker.className = 'nexa-association-picker';
        picker.innerHTML = '<label><span class="fas fa-search" aria-hidden="true"></span><span class="sr-only"></span><input type="search" data-association-search autocomplete="off"></label><div class="nexa-association-candidates"></div>';
        const input = picker.querySelector('input');
        input.dataset.definitionId = relationship.id;
        input.placeholder = `Search ${relationship.relatedEntityLabel.toLowerCase()}`;
        input.value = query;
        picker.querySelector('.sr-only').textContent = input.placeholder;
        const candidates = picker.querySelector('.nexa-association-candidates');
        if (!data.candidates.length) candidates.innerHTML = `<p class="nexa-association-empty">${query ? 'No matching records found.' : 'No available records found.'}</p>`;
        data.candidates.forEach(candidate => {
            const row = document.createElement('button');
            row.type = 'button';
            row.className = 'nexa-association-candidate';
            row.dataset.action = 'connect-record';
            row.dataset.definitionId = relationship.id;
            row.dataset.candidateId = candidate.id;
            row.dataset.currentIsSource = String(Boolean(relationship.currentIsSource));
            row.innerHTML = '<span class="nexa-association-avatar" aria-hidden="true"></span><span><strong></strong><small></small></span><span class="fas fa-plus" aria-hidden="true"></span>';
            row.querySelector('.nexa-association-avatar').textContent = candidate.label.charAt(0).toUpperCase() || 'R';
            row.querySelector('strong').textContent = candidate.label;
            row.querySelector('small').textContent = relationship.relatedEntityLabel.replace(/s$/, '');
            candidates.append(row);
        });
        picker.append(candidates);
        section.append(picker);
    }

    searchAssociationCandidates(event) {
        const input = event.currentTarget;
        const definitionId = input.dataset.definitionId;
        window.clearTimeout(this.associationSearchTimers.get(definitionId));
        const timer = window.setTimeout(() => this.loadRelationshipSection(definitionId, input.value.trim()), 250);
        this.associationSearchTimers.set(definitionId, timer);
    }

    async connectRecord(event) {
        const button = event.currentTarget;
        if (!this.associationRecord) return;
        const currentIsSource = button.dataset.currentIsSource === 'true';
        const payload = {
            relationshipDefinitionId: button.dataset.definitionId,
            sourceEntityId: currentIsSource ? this.associationRecord.entityId : button.dataset.candidateId,
            targetEntityId: currentIsSource ? button.dataset.candidateId : this.associationRecord.entityId,
        };
        button.disabled = true;
        try {
            await Espo.Ajax.postRequest('Nexa/customization/relationships/link', payload);
            Espo.Ui.success('Record connected.');
            await this.loadRelationshipSection(button.dataset.definitionId);
        } catch (error) {
            button.disabled = false;
            Espo.Ui.error(error?.message || 'The record could not be connected.');
        }
    }

    async unlinkRecord(event) {
        const button = event.currentTarget;
        if (!window.confirm('Remove this association? The records themselves will not be deleted.')) return;
        button.disabled = true;
        try {
            await Espo.Ajax.deleteRequest(`Nexa/customization/relationships/link/${encodeURIComponent(button.dataset.linkId)}`);
            Espo.Ui.success('Association removed.');
            await this.loadRelationshipSection(button.dataset.definitionId);
        } catch (error) {
            button.disabled = false;
            Espo.Ui.error(error?.message || 'The association could not be removed.');
        }
    }

    async saveCustomRecord(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const values = {};
        [...form.elements].forEach(input => {
            if (!input.name?.startsWith('value:')) return;
            const key = input.name.slice(6);
            if (input.type === 'checkbox') values[key] = input.checked;
            else if (input.multiple) values[key] = [...input.selectedOptions].map(option => option.value);
            else values[key] = input.value;
        });
        try {
            await Espo.Ajax.postRequest(`Nexa/customization/entities/${encodeURIComponent(form.dataset.entityKey)}/records`, {displayName: form.elements.displayName.value, values});
            Espo.Ui.success('Record created.');
            await this.loadRecords();
        } catch (error) {
            Espo.Ui.error(error?.message || 'The record could not be created.');
        }
    }

    formData(form) {
        return Object.fromEntries([...new FormData(form).entries()]);
    }

    typeLabel(value) {
        const labels = {
            text: 'Short text',
            long_text: 'Multi-line text',
            number: 'Number',
            currency: 'Currency',
            date: 'Date',
            datetime: 'Date and time',
            boolean: 'Yes / No',
            single_select: 'Dropdown',
            multi_select: 'Multiple choice',
            url: 'Website URL',
            email: 'Email address',
            phone: 'Phone number',
            user: 'Nexa user',
        };
        if (labels[value]) return labels[value];
        return String(value || '').replaceAll('_', ' ').replace(/\b\w/g, character => character.toUpperCase());
    }
});
