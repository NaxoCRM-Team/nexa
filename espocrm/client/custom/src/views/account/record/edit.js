define('custom:views/account/record/edit', ['views/record/edit', 'custom:helpers/custom-properties'], (Dep, CustomProperties) => class extends Dep {
    setup() {
        super.setup();
        this.nexaCustomProperties = new CustomProperties(this, 'Account', this.model.isNew() ? 'create' : 'edit');
        this.listenTo(this.model, 'sync', () => this.nexaCustomProperties.save());
    }
    afterRender() {
        const result = super.afterRender();
        this.nexaCustomProperties.mount(this.element.querySelector('.middle') || this.element.querySelector('.record') || this.element);
        return result;
    }
});
