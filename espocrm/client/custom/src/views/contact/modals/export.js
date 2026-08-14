define('custom:views/contact/modals/export', ['views/export/modals/export'], Dep => class extends Dep {
    template = 'custom:contact/modals/export';
    cssName = 'nexa-contact-export';
    className = 'dialog dialog-record nexa-contact-export-dialog';

    setup() {
        super.setup();
        this.exportName = '';
        this.on('proceed', data => data.exportName = this.exportName);
        this.buttonList = [
            {name: 'export', label: 'Export contacts', style: 'primary', title: 'Ctrl+Enter'},
            {name: 'cancel', label: 'Cancel'},
        ];
    }

    data() {
        const count = Number.isFinite(Number(this.options.count)) ? Number(this.options.count) : 0;

        return {
            count: count.toLocaleString(),
            source: this.options.source || 'Selected contacts',
            defaultExportName: `Contacts ${new Date().toISOString().slice(0, 10)}`,
        };
    }

    actionExport() {
        const input = this.element.querySelector('[data-name="exportName"]');
        const error = this.element.querySelector('[data-name="exportNameError"]');
        const value = (input?.value || '').trim();
        const valid = /^[a-zA-Z0-9][a-zA-Z0-9 _()-]{0,99}$/.test(value);

        input?.classList.toggle('has-error', !valid);
        error?.classList.toggle('hidden', valid);

        if (!valid) {
            input?.focus();
            return;
        }

        this.exportName = value;
        return super.actionExport();
    }
});
