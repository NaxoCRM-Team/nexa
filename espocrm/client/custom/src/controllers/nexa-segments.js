define('custom:controllers/nexa-segments', ['controller'], Dep => class extends Dep {
    actionIndex() {
        this.main('custom:views/segment/workspace', {}, view => view.render());
    }
});
