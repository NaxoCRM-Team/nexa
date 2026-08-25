define('custom:helpers/custom-properties', [], () => class {
    constructor(view, entityType, mode = 'edit') {
        this.view = view;
        this.entityType = entityType;
        this.mode = mode;
        this.values = {};
        this.definitions = [];
    }

    async mount(host) {
        if (!host || host.querySelector('[data-nexa-custom-properties]')) return;
        try {
            const response = this.view.model?.id
                ? await Espo.Ajax.getRequest(`Nexa/customization/values/${encodeURIComponent(this.entityType)}/${encodeURIComponent(this.view.model.id)}`)
                : await Espo.Ajax.getRequest('Nexa/customization/definitions', {entityType: this.entityType});
            this.definitions = response.definitions || response.fields || [];
            this.values = response.values || {};
            if (!this.definitions.length) return;
            const savedLayout = (response.layouts || []).find(item => item.layout_context === this.mode);
            const layout = savedLayout?.layout || [];
            if (savedLayout) {
                const order = new Map(layout.map((key, index) => [key, index]));
                this.definitions = this.definitions.filter(definition => order.has(definition.field_key));
                this.definitions.sort((a, b) => (order.get(a.field_key) ?? 9999) - (order.get(b.field_key) ?? 9999));
            }
            const section = document.createElement('section');
            section.className = `nexa-custom-properties nexa-custom-properties-${this.mode}`;
            section.dataset.nexaCustomProperties = 'true';
            section.innerHTML = `<header><div><p>Tenant properties</p><h3>Additional information</h3></div></header><div class="nexa-custom-property-grid"></div>`;
            const grid = section.querySelector('.nexa-custom-property-grid');
            this.definitions.forEach(definition => grid.append(this.field(definition)));
            host.append(section);
            this.section = section;
        } catch (error) {
            console.warn('Unable to load tenant custom properties.', error);
        }
    }

    field(definition) {
        const wrapper = document.createElement('div');
        wrapper.className = 'nexa-custom-property';
        wrapper.dataset.fieldKey = definition.field_key;
        const label = document.createElement('label');
        label.textContent = definition.label;
        if (definition.is_required) label.append(Object.assign(document.createElement('span'), {textContent: ' *'}));
        const value = this.values[definition.field_key] ?? definition.defaultValue ?? '';
        if (this.mode === 'detail') {
            const output = document.createElement('div');
            output.className = 'nexa-custom-property-value';
            output.textContent = this.displayValue(definition, value);
            wrapper.append(label, output);
            return wrapper;
        }
        const input = this.input(definition, value);
        input.dataset.nexaCustomField = definition.field_key;
        if (definition.is_required) input.required = true;
        if (definition.description) input.setAttribute('aria-describedby', `nexa-help-${definition.id}`);
        wrapper.append(label, input);
        if (definition.description) {
            const help = document.createElement('small'); help.id = `nexa-help-${definition.id}`; help.textContent = definition.description; wrapper.append(help);
        }
        return wrapper;
    }

    input(definition, value) {
        if (definition.data_type === 'long_text') {
            const input = document.createElement('textarea'); input.rows = 3; input.value = value || ''; return input;
        }
        if (['single_select', 'multi_select'].includes(definition.data_type)) {
            const select = document.createElement('select'); select.multiple = definition.data_type === 'multi_select';
            if (!select.multiple) select.append(new Option('Select...', ''));
            (definition.options || []).forEach(option => select.append(new Option(option, option, false, Array.isArray(value) ? value.includes(option) : value === option)));
            return select;
        }
        if (definition.data_type === 'boolean') {
            const input = document.createElement('input'); input.type = 'checkbox'; input.checked = Boolean(value); return input;
        }
        const input = document.createElement('input');
        input.type = ({number:'number',currency:'number',date:'date',datetime:'datetime-local',email:'email',url:'url',phone:'tel'}[definition.data_type] || 'text');
        if (definition.data_type === 'currency') input.step = '0.01';
        if (definition.data_type === 'number') input.step = 'any';
        input.value = value ?? '';
        return input;
    }

    collect() {
        const values = {};
        this.definitions.forEach(definition => {
            const input = this.section?.querySelector(`[data-nexa-custom-field="${CSS.escape(definition.field_key)}"]`);
            if (!input) return;
            if (definition.data_type === 'boolean') values[definition.field_key] = input.checked;
            else if (definition.data_type === 'multi_select') values[definition.field_key] = [...input.selectedOptions].map(option => option.value);
            else values[definition.field_key] = input.value;
        });
        return values;
    }

    async save() {
        if (!this.section || !this.view.model?.id) return;
        try {
            await Espo.Ajax.postRequest(`Nexa/customization/values/${encodeURIComponent(this.entityType)}/${encodeURIComponent(this.view.model.id)}`, {values: this.collect()});
        } catch (error) {
            Espo.Ui.error(error?.message || 'The additional properties could not be saved.');
            throw error;
        }
    }

    displayValue(definition, value) {
        if (value === null || value === '' || (Array.isArray(value) && !value.length)) return 'Not recorded';
        if (definition.data_type === 'boolean') return value ? 'Yes' : 'No';
        if (Array.isArray(value)) return value.join(', ');
        return String(value);
    }
});
