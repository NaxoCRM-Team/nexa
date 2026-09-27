define('custom:controllers/nexa-assets', ['controller'], Dep => class extends Dep {
    actionIndex() { this.main('custom:views/asset/workspace', {}, view => view.render()); }
});
