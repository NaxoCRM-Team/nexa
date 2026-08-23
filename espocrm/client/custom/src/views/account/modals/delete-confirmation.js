define('custom:views/account/modals/delete-confirmation', ['views/modal'], Dep => class extends Dep {
    template = 'custom:account/modals/delete-confirmation';
    cssName = 'nexa-delete-confirmation-modal';
    className = 'dialog nexa-delete-dialog';
    noFullHeight = true;

    setup() {
        this.count = Number(this.options.count || 0);
        this.headerText = `Delete ${this.count} ${this.count === 1 ? 'record' : 'records'}?`;
        this.addButton({name: 'delete', label: 'Delete', style: 'danger', disabled: true});
        this.addButton({name: 'cancel', label: 'Cancel'});
    }

    data() {
        return {count: this.count, recordLabel: this.count === 1 ? 'record' : 'records'};
    }

    afterRender() {
        super.afterRender();
        const input = this.element.querySelector('[data-name="confirmationCount"]');
        input?.addEventListener('input', () => {
            Number(input.value) === this.count ? this.enableButton('delete') : this.disableButton('delete');
        });
        input?.addEventListener('keydown', event => {
            if (event.key === 'Enter' && Number(input.value) === this.count) this.actionDelete();
        });
        input?.focus();
    }

    actionDelete() {
        if (Number(this.element.querySelector('[data-name="confirmationCount"]')?.value) !== this.count) return;
        this.trigger('confirm');
        this.close();
    }
});
