define('custom:controllers/nexa-forms', ['controller'], Dep => class extends Dep {
    actionIndex() {
        this.main('custom:views/form/workspace', {}, view => view.render());
    }
});
