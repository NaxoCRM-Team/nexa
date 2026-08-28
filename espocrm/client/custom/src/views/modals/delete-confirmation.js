define('custom:views/modals/delete-confirmation', ['views/modal'], Dep => class extends Dep {
    template = 'custom:modals/delete-confirmation';
    cssName = 'nexa-delete-confirmation-modal';
    className = 'dialog nexa-delete-dialog';
    noFullHeight = true;

    setup() {
        this.count = Number(this.options.count || 0);
        this.entityLabel = String(this.options.entityLabel || 'records');
        this.headerText = `Delete ${this.count} ${this.count === 1 ? 'record' : 'records'}?`;
        this.addButton({name: 'delete', label: 'Delete', style: 'danger', disabled: true});
        this.addButton({name: 'cancel', label: 'Cancel'});
    }

    data() {
        return {
            count: this.count,
            entityLabel: this.entityLabel.toLocaleLowerCase(),
            recordLabel: this.count === 1 ? 'record' : 'records',
        };
    }

    afterRender() {
        super.afterRender();
        const input = this.element.querySelector('[data-name="confirmationCount"]');
        const update = () => Number(input?.value) === this.count ?
            this.enableButton('delete') : this.disableButton('delete');

        input?.addEventListener('input', update);
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
