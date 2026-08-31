define('custom:views/case/record/edit', ['views/record/edit'], Dep => class extends Dep {
    setup() { super.setup(); document.body.classList.add('nexa-case-record-page'); this.once('remove', () => document.body.classList.remove('nexa-case-record-page')); }
    afterRender() {
        const result = super.afterRender();
        const heading = document.querySelector('#main .page-header h3, #main .page-header h4');
        if (heading && this.model.isNew()) heading.textContent = 'New Case';
        return result;
    }
});
