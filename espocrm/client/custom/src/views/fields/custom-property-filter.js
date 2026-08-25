define('custom:views/fields/custom-property-filter', ['views/fields/base'], Dep => class extends Dep {
    type = 'varchar';
    listTemplate = 'custom:fields/custom-property-filter/search';
    detailTemplate = 'custom:fields/custom-property-filter/search';
    editTemplate = 'custom:fields/custom-property-filter/search';
    searchTemplate = 'custom:fields/custom-property-filter/search';

    events = {
        'change [data-custom-property]': 'changeProperty',
        'change [data-custom-operator]': 'changeOperator',
        'input [data-custom-value]': 'changeValue',
        'change [data-custom-value]': 'changeValue',
    };

    setupSearch() {
        const data = this.searchParams?.data || {};
        this.selectedKey = data.fieldKey || '';
        this.selectedOperator = data.operator || 'equals';
        this.selectedValue = data.value ?? '';
        this.wait(this.loadDefinitions());
    }

    async loadDefinitions() {
        const result = await Espo.Ajax.getRequest('Nexa/customization/definitions', {entityType: this.entityType});
        this.definitions = (result.fields || []).filter(field => field.is_filterable);
    }

    data() {
        return {
            properties: (this.definitions || []).map(field => ({
                key: field.field_key,
                label: field.label,
                selected: field.field_key === this.selectedKey,
            })),
            empty: !(this.definitions || []).length,
        };
    }

    afterRender() {
        super.afterRender();
        this.renderOperatorAndValue();
    }

    changeProperty(event) {
        this.selectedKey = event.currentTarget.value;
        this.selectedOperator = 'equals';
        this.selectedValue = '';
        this.renderOperatorAndValue();
        this.trigger('change');
    }

    changeValue() {
        this.trigger('change');
    }

    changeOperator(event) {
        this.selectedOperator = event.currentTarget.value;
        this.selectedValue = this.element.querySelector('[data-custom-value]')?.value ?? '';
        this.renderOperatorAndValue();
        this.trigger('change');
    }

    currentDefinition() {
        const key = this.element.querySelector('[data-custom-property]')?.value || this.selectedKey;
        return (this.definitions || []).find(field => field.field_key === key) || null;
    }

    renderOperatorAndValue() {
        const definition = this.currentDefinition();
        const operator = this.element.querySelector('[data-custom-operator]');
        const valueHost = this.element.querySelector('[data-custom-value-host]');
        if (!operator || !valueHost) return;
        operator.replaceChildren();
        valueHost.replaceChildren();
        if (!definition) {
            operator.disabled = true;
            return;
        }

        const numeric = ['number', 'currency', 'date', 'datetime'].includes(definition.data_type);
        const options = numeric
            ? [['equals','Equals'],['not_equals','Does not equal'],['greater_than','Greater than'],['greater_or_equal','At least'],['less_than','Less than'],['less_or_equal','At most'],['empty','Is empty'],['not_empty','Is not empty']]
            : [['equals','Equals'],['not_equals','Does not equal'],['contains','Contains'],['empty','Is empty'],['not_empty','Is not empty']];
        options.forEach(([key, label]) => operator.append(new Option(label, key, false, key === this.selectedOperator)));
        operator.disabled = false;

        const needsValue = !['empty', 'not_empty'].includes(operator.value);
        if (!needsValue) return;
        let input;
        if (['single_select', 'multi_select'].includes(definition.data_type)) {
            input = document.createElement('select');
            input.append(new Option('Select a value', ''));
            (definition.options || []).forEach(option => input.append(new Option(option, option)));
            input.value = this.selectedValue;
        } else if (definition.data_type === 'boolean') {
            input = document.createElement('select');
            input.append(new Option('Yes', 'true'), new Option('No', 'false'));
            input.value = String(this.selectedValue || 'true');
        } else {
            input = document.createElement('input');
            input.type = ({number: 'number', currency: 'number', date: 'date', datetime: 'datetime-local'}[definition.data_type] || 'text');
            input.value = this.selectedValue;
            input.placeholder = `Enter ${definition.label.toLowerCase()}`;
        }
        input.className = 'form-control';
        input.dataset.customValue = '';
        valueHost.append(input);
    }

    fetchSearch() {
        const fieldKey = this.element.querySelector('[data-custom-property]')?.value || '';
        const operator = this.element.querySelector('[data-custom-operator]')?.value || 'equals';
        const value = this.element.querySelector('[data-custom-value]')?.value ?? '';
        if (!fieldKey || (!['empty', 'not_empty'].includes(operator) && value === '')) return null;
        const data = {fieldKey, operator, value};
        return {type: 'nexaCustomProperty', value: JSON.stringify(data), data};
    }

    populateDefaults() {}
});
