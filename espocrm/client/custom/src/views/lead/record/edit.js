define('custom:views/lead/record/edit', ['views/record/edit', 'custom:helpers/custom-properties'], (Dep, CustomProperties) => class extends Dep {
    setup() {
        super.setup();
        this.nexaCustomProperties = new CustomProperties(this, 'Lead', this.model.isNew() ? 'create' : 'edit');
        this.listenTo(this.model, 'sync', () => this.nexaCustomProperties.save());
        this.once('remove', () => document.body.classList.remove('nexa-lead-record-page'));
    }

    afterRender() {
        const result = super.afterRender();
        document.body.classList.add('nexa-lead-record-page');
        this.nexaCustomProperties.mount(this.element.querySelector('.middle') || this.element.querySelector('.record') || this.element);
        return result;
    }
});
