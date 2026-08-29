define('custom:controllers/nexa-currency', ['controller'], Dep => class extends Dep {
    actionIndex() {
        if (!this.getUser().isAdmin()) {
            Espo.Ui.error('Only a tenant administrator can manage workspace currencies.');
            this.getRouter().navigate('#Home', {trigger: true});
            return;
        }
        this.main('custom:views/currency/admin', {}, view => view.render());
    }
});
