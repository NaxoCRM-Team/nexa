define('custom:views/lead/list', ['views/list'], Dep => class extends Dep {
    setup() {
        super.setup();
        this.once('remove', () => document.body.classList.remove('nexa-lead-list-page'));
    }

    afterRender() {
        const result = super.afterRender();
        document.body.classList.add('nexa-lead-list-page');
        const input = this.element?.querySelector('input[data-name="textFilter"]');
        if (input) input.placeholder = 'Search leads by name, company, email or phone';
        return result;
    }
});
