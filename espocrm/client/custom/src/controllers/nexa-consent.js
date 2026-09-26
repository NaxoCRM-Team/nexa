define('custom:controllers/nexa-consent', ['controller'], Dep => class extends Dep {
    actionIndex() {
        if (!this.getUser().isAdmin()) {
            Espo.Ui.error('Only a tenant administrator can manage consent governance.');
            this.getRouter().navigate('#Home', {trigger: true});
            return;
        }
        this.main('custom:views/consent/workspace', {}, view => view.render());
    }
});
