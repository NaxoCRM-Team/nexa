define('custom:views/lead/record/detail', ['crm:views/lead/record/detail', 'custom:helpers/custom-properties'], (Dep, CustomProperties) => class extends Dep {
    setup() {
        super.setup();
        this.nexaCustomProperties = new CustomProperties(this, 'Lead', 'detail');
        this.once('remove', () => document.body.classList.remove('nexa-lead-record-page'));
    }

    afterRender() {
        const result = super.afterRender();
        document.body.classList.add('nexa-lead-record-page');
        this.element?.querySelector('.record')?.classList.add('nexa-lead-record');
        this.nexaCustomProperties.mount(this.element.querySelector('.middle') || this.element.querySelector('.record') || this.element);
        return result;
    }
});
