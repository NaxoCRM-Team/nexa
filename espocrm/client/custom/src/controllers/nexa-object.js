define('custom:controllers/nexa-object', ['controller'], Dep => class extends Dep {
    actionIndex(options = {}) {
        const entityKey = options.entity ? decodeURIComponent(options.entity) : null;

        if (!entityKey) {
            Espo.Ui.error('Choose a custom object from the CRM menu.');
            this.getRouter().navigate('#Home', {trigger: true});
            return;
        }

        this.main('custom:views/customization/runtime', {
            entityKey,
            recordId: options.record ? decodeURIComponent(options.record) : null,
            mode: options.mode ? decodeURIComponent(options.mode) : null,
        }, view => view.render());
    }
});
