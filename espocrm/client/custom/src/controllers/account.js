define('custom:controllers/account', ['controllers/record'], Dep => class extends Dep {
    actionImport() {
        this.main('custom:views/account/import', {}, view => view.render());
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
