define('custom:views/account/modals/permanent-delete-confirmation', ['views/modal'], Dep => class extends Dep {
    template = 'custom:account/modals/permanent-delete-confirmation';
    cssName = 'nexa-permanent-delete-confirmation-modal';
    className = 'dialog nexa-permanent-delete-dialog';
    noFullHeight = true;

    setup() {
        this.count = Number(this.options.count) || 0;
        this.headerText = `Permanently delete ${this.count} ${this.count === 1 ? 'record' : 'records'}?`;
        this.addButton({name: 'confirm', label: 'Permanently delete', style: 'danger', disabled: true});
        this.addButton({name: 'cancel', label: 'Cancel'});
    }

    data() {
        return {count: this.count, recordLabel: this.count === 1 ? 'account' : 'accounts'};
    }

    afterRender() {
        super.afterRender();
        const input = this.element.querySelector('[data-name="confirmation"]');
        input?.addEventListener('input', () => {
            Number(input.value) === this.count ? this.enableButton('confirm') : this.disableButton('confirm');
        });
        input?.addEventListener('keydown', event => {
            if (event.key !== 'Enter' || Number(input.value) !== this.count) return;
            event.preventDefault();
            this.actionConfirm();
        });
        input?.focus();
    }

    actionConfirm() {
        if (Number(this.element.querySelector('[data-name="confirmation"]')?.value) !== this.count) return;
        this.trigger('confirm');
        this.close();
    }
});
