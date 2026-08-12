define('custom:views/account/record/list-inline', ['views/record/list', 'custom:table-inline-editor'],
    (Dep, TableInlineEditor) => class extends Dep {
        setup() {
            super.setup();
            this.inlineEditor = new TableInlineEditor(this, 'Account', {
                type: {type: 'dropdown'},
                industry: {type: 'dropdown'},
                annualRevenue: {type: 'text', inputType: 'number', normalize: value => value === '' ? null : Number(value)},
                numberOfEmployees: {type: 'text', inputType: 'number', normalize: value => value === '' ? null : Number.parseInt(value, 10)},
                billingAddressCountry: {type: 'text', maxLength: 100},
            });
            this.inlineEditor.setup();
        }

        afterRender() {
            const result = super.afterRender();
            this.inlineEditor.decorate();
            this.inlineObserver = new MutationObserver(() => this.inlineEditor.decorate());
            this.inlineObserver.observe(this.element, {childList: true, subtree: true});
            this.once('remove', () => this.inlineObserver?.disconnect());
            return result;
        }
    }
);
