define('custom:controllers/contact', ['controllers/record'], Dep => {
    return class extends Dep {
        actionImport() {
            this.main('custom:views/contact/import', {}, view => view.render());
        }
    };
});
