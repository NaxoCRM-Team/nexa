define('custom:controllers/nexa-tracking', ['controller'], Dep => class extends Dep {
    actionIndex() {
        if (!this.getUser().isAdmin()) {
            Espo.Ui.error('Only a tenant administrator can manage website tracking.');
            this.getRouter().navigate('#Home', {trigger: true});
            return;
        }
        this.main('custom:views/tracking/workspace', {}, view => view.render());
    }
});
