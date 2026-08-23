define('custom:views/account/modals/export', ['custom:views/contact/modals/export'], Dep => class extends Dep {
    template = 'custom:account/modals/export';
    cssName = 'nexa-contact-export';
    className = 'dialog dialog-record nexa-contact-export-dialog nexa-account-export-dialog';

    setup() {
        super.setup();
        this.buttonList = [
            {name: 'export', label: 'Export accounts', style: 'primary', title: 'Ctrl+Enter'},
            {name: 'cancel', label: 'Cancel'},
        ];
    }

    data() {
        const count = Number.isFinite(Number(this.options.count)) ? Number(this.options.count) : 0;

        return {
            count: count.toLocaleString(),
            source: this.options.source || 'Selected accounts',
            defaultExportName: `Accounts ${new Date().toISOString().slice(0, 10)}`,
        };
    }
});
