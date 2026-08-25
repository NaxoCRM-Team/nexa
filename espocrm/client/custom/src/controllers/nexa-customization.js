define('custom:controllers/nexa-customization', ['controller'], Dep => class extends Dep {
    actionIndex() {
        if (!this.getUser().isAdmin()) {
            Espo.Ui.error('Only a tenant administrator can manage properties and custom objects.');
            this.getRouter().navigate('#Home', {trigger: true});
            return;
        }
        this.main('custom:views/customization/admin', {}, view => view.render());
    }
});
