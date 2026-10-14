define('custom:controllers/nexa-email-experiments', ['controller'], Dep => class extends Dep {
    actionIndex() { this.main('custom:views/email-experiment/workspace', {}, view => view.render()); }
});
