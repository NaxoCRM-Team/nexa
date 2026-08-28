define('custom:views/opportunity/record/edit', ['crm:views/opportunity/record/edit'], Dep => class extends Dep {
    afterRender() {
        const result = super.afterRender();
        document.body.classList.add('nexa-opportunity-edit-page');
        this.element?.classList.add('nexa-opportunity-edit');
        return result;
    }
    remove() { document.body.classList.remove('nexa-opportunity-edit-page'); return super.remove(); }
});
