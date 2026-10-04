define('custom:controllers/nexa-marketing-email', ['controller'], Dep => class extends Dep {
    actionIndex() { this.main('custom:views/marketing-email/workspace', {}, view => view.render()); }
});
