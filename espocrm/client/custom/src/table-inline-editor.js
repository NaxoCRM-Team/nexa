define('custom:table-inline-editor', [], () => class NexaTableInlineEditor {
    constructor(view, entityType, fieldConfig) {
        this.view = view;
        this.entityType = entityType;
        this.fieldConfig = fieldConfig;
    }

    setup() {
        this.view.addHandler('dblclick', 'tbody td.cell', (event, target) => this.start(event, target));
        this.view.addHandler('click', 'tbody td.cell .nexa-cell-edit-trigger', (event, target) => this.start(event, target));
        this.view.addHandler('keydown', 'tbody td.cell', (event, target) => {
            if (['Enter', 'F2'].includes(event.key)) this.start(event, target);
        });
        this.view.once('remove', () => this.destroy());
    }

    decorate() {
        Object.entries(this.fieldConfig).forEach(([field, config]) => {
            this.view.element?.querySelectorAll(`tbody td.cell[data-name="${field}"]`).forEach(cell => {
                cell.dataset.nexaInlineReady = 'true';
                cell.dataset.field = field;
                cell.dataset.type = config.type;
                cell.dataset.options = this.getOptions(field, config).join(',');
                cell.classList.add('nexa-inline-editable-cell');
                cell.tabIndex = 0;
                cell.title = 'Double-click to edit';
                cell.setAttribute('aria-label', `${this.view.translate(field, 'fields', this.entityType)}. Double-click or press Enter to edit.`);

                if (cell.querySelector(':scope > .nexa-cell-edit-trigger')) return;

                const trigger = document.createElement('button');
                trigger.type = 'button';
                trigger.className = 'nexa-cell-edit-trigger';
                trigger.title = 'Edit cell';
                trigger.setAttribute('aria-label', `Edit ${this.view.translate(field, 'fields', this.entityType)}`);
                trigger.innerHTML = '<span class="fas fa-pencil-alt" aria-hidden="true"></span>';
                cell.append(trigger);
            });
        });
    }

    getOptions(field, config) {
        return config.options || this.view.getMetadata().get(`entityDefs.${this.entityType}.fields.${field}.options`) || [];
    }

    start(event, target) {
        if (this.saving) return;

        const cell = target?.closest('td.cell');
        const row = cell?.closest('tr[data-id]');
        const field = cell?.dataset.field;
        const config = this.fieldConfig[field];
        const model = row ? this.view.collection.get(row.dataset.id) : null;

        if (!cell || !model || !config || cell === this.activeCell || this.activeCell) return;
        // Configuration is the client allowlist; ACL and the tenant-scoped API
        // remain the authoritative record and field security boundaries.
        if (!this.view.getAcl().checkModel(model, 'edit') || !this.view.getAcl().checkField(this.entityType, field, 'edit')) {
            Espo.Ui.error(this.view.translate('Access denied'));
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        this.cancel();

        const originalValue = model.get(field) ?? '';
        const editor = config.type === 'dropdown'
            ? this.createSelect(field, config, originalValue)
            : this.createInput(config, originalValue);

        this.activeCell = cell;
        this.activeModel = model;
        this.activeField = field;
        this.originalValue = originalValue;
        this.originalHtml = cell.innerHTML;
        cell.classList.add('nexa-inline-cell-editing');
        cell.replaceChildren(editor);
        editor.focus();
        if (editor instanceof HTMLInputElement) editor.select();

        editor.addEventListener('keydown', keyEvent => {
            if (keyEvent.key === 'Enter') {
                keyEvent.preventDefault();
                this.save(editor.value, config);
            } else if (keyEvent.key === 'Escape') {
                keyEvent.preventDefault();
                this.cancel();
            }
        });
        editor.addEventListener('blur', () => window.setTimeout(() => {
            if (cell === this.activeCell) this.save(editor.value, config);
        }, 0), {once: true});
        if (editor instanceof HTMLSelectElement) editor.addEventListener('change', () => this.save(editor.value, config), {once: true});
    }

    createInput(config, value) {
        const input = document.createElement('input');
        input.type = config.inputType || 'text';
        input.className = 'nexa-cell-editor nexa-cell-editor-input';
        input.value = value;
        if (config.maxLength) input.maxLength = config.maxLength;
        input.setAttribute('aria-label', 'Edit cell value');
        return input;
    }

    createSelect(field, config, value) {
        const select = document.createElement('select');
        select.className = 'nexa-cell-editor nexa-cell-editor-select';
        select.setAttribute('aria-label', 'Edit cell value');
        this.getOptions(field, config).forEach(optionValue => {
            const option = document.createElement('option');
            option.value = optionValue;
            option.textContent = this.getOptionLabel(field, optionValue);
            option.selected = optionValue === value;
            select.append(option);
        });
        return select;
    }

    getOptionLabel(field, value) {
        if (value === '') return this.view.translate('None');
        return this.view.getLanguage().translateOption(value, field, this.entityType) || value;
    }

    async save(rawValue, config) {
        if (!this.activeCell || this.saving) return;

        const value = config.normalize ? config.normalize(rawValue) : rawValue.trim();
        if ((config.inputType === 'number' && value !== null && !Number.isFinite(value)) ||
            (typeof config.validate === 'function' && !config.validate(value))) {
            this.activeCell.classList.add('nexa-inline-cell-error');
            Espo.Ui.error(this.view.translate('Not valid'));
            return;
        }
        if (value === this.originalValue) return this.cancel();

        const {activeCell: cell, activeModel: model, activeField: field} = this;
        const displayValue = config.type === 'dropdown' ? this.getOptionLabel(field, value) : value;
        this.saving = true;
        cell.classList.remove('nexa-inline-cell-editing');
        cell.classList.add('nexa-inline-cell-saving');
        // Paint the new value immediately, then restore the captured formatted
        // markup if the authenticated PATCH is rejected.
        cell.textContent = displayValue || '—';

        try {
            const persistedValue = value === '' ? null : value;
            if (config.save) {
                await config.save(model, persistedValue);
                model.set(field, persistedValue);
            } else {
                await model.save({[field]: persistedValue}, {patch: true});
            }
            cell.classList.remove('nexa-inline-cell-saving');
            cell.classList.add('nexa-inline-cell-saved');
            this.reset();
            window.setTimeout(() => cell.classList.remove('nexa-inline-cell-saved'), 1600);
            const fieldView = this.view.getView(model.id)?.getView(`${field}Field`);
            if (fieldView) await fieldView.reRender();
            this.decorate();
        } catch (error) {
            model.set(field, this.originalValue, {silent: true});
            cell.innerHTML = this.originalHtml;
            cell.classList.remove('nexa-inline-cell-saving');
            cell.classList.add('nexa-inline-cell-error');
            this.reset();
            Espo.Ui.error(this.view.translate('Error occurred'));
            window.setTimeout(() => cell.classList.remove('nexa-inline-cell-error'), 2200);
        }
    }

    cancel() {
        if (!this.activeCell || this.saving) return;
        this.activeCell.innerHTML = this.originalHtml;
        this.activeCell.classList.remove('nexa-inline-cell-editing');
        this.reset();
    }

    reset() {
        this.activeCell = this.activeModel = this.activeField = this.originalValue = this.originalHtml = null;
        this.saving = false;
    }

    destroy() {
        this.cancel();
        this.reset();
    }
});
