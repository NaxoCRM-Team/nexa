define('custom:controllers/nexa-workflows', ['controller'], Dep => class extends Dep {
    actionIndex() { this.main('custom:views/automation/workspace', {}, view => view.render()); }
});
