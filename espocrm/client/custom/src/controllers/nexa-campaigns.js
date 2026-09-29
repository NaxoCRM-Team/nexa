define('custom:controllers/nexa-campaigns', ['controller'], Dep => class extends Dep {
    actionIndex() { this.main('custom:views/campaign/workspace', {}, view => view.render()); }
});
