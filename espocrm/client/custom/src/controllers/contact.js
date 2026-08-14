define('custom:controllers/contact', ['controllers/record'], Dep => {
    return class extends Dep {
        actionImport() {
            this.main('custom:views/contact/import', {}, view => view.render());
        }

        actionExportAudit() {
            this.main('custom:views/contact/export-audit', {}, view => view.render());
        }

        actionTrash() {
            if (!this.getUser().isAdmin()) {
                Espo.Ui.error('Only a tenant administrator can restore deleted records.');
                this.getRouter().navigate('#Contact', {trigger: true});
                return;
            }

            this.main('custom:views/contact/trash', {}, view => view.render());
        }
    };
});
