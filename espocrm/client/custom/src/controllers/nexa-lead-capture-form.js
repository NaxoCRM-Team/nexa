define([
    'controllers/lead-capture-form',
    'custom:views/form/public-form',
], (Dep, PublicFormView) => class extends Dep {
    actionShow(options) {
        this.prepareContainer();
        const view = new PublicFormView({formData: options});
        view.setSelector('body > .content');
        this.viewFactory.prepare(view, () => view.render());
    }
});
