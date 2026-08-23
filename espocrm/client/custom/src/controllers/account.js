define('custom:controllers/account', ['controllers/record'], Dep => class extends Dep {
    actionImport() {
        const formData = {
            entityType: 'Account',
            action: 'create',
            headerRow: true,
            delimiter: ',',
            textQualifier: '"',
            dateFormat: 'YYYY-MM-DD',
            timeFormat: 'HH:mm:ss',
            currency: this.getConfig().get('defaultCurrency'),
            timezone: 'UTC',
            decimalMark: '.',
            personNameFormat: 'f l',
            idleMode: false,
            skipDuplicateChecking: false,
            silentMode: true,
            manualMode: false,
        };

        // Use Espo's audited import engine with Account locked in as the
        // initial entity; its creates still pass through tenant-scoped ORM.
        this.main('views/import/index', {formData}, view => view.render());
    }

    actionTrash() {
        if (!this.getUser().isAdmin()) {
            Espo.Ui.error('Only a tenant administrator can restore deleted accounts.');
            this.getRouter().navigate('#Account', {trigger: true});
            return;
        }

        this.main('custom:views/account/trash', {}, view => view.render());
    }
});
