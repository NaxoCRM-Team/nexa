define('custom:views/contact/modals/trash', ['views/modal'], Dep => class extends Dep {
    template = 'custom:contact/modals/trash';
    cssName = 'nexa-contact-trash-modal';
    className = 'dialog nexa-trash-dialog';
    noFullHeight = true;

    setup() {
        this.records = this.options.records || [];
        this.headerText = 'Deleted contacts';
        this.addButton({name: 'restore', label: 'Restore selected', style: 'primary', disabled: true});
        this.addButton({name: 'cancel', label: 'Close'});
    }

    data() {
        return {records: this.records, hasRecords: this.records.length > 0};
    }

    afterRender() {
        super.afterRender();
        this.element.querySelectorAll('[data-name="trashContact"]').forEach(input => {
            input.addEventListener('change', () => this.updateRestoreButton());
        });
    }

    updateRestoreButton() {
        this.getSelectedIds().length ? this.enableButton('restore') : this.disableButton('restore');
    }

    getSelectedIds() {
        return [...this.element.querySelectorAll('[data-name="trashContact"]:checked')]
            .map(input => input.value);
    }

    async actionRestore() {
        const ids = this.getSelectedIds();
        if (!ids.length) return;

        this.disableButton('restore');
        Espo.Ui.notifyWait();
        try {
            const result = await Espo.Ajax.postRequest('Nexa/contact/trash/restore', {ids});
            Espo.Ui.success(`${result.count} ${result.count === 1 ? 'contact' : 'contacts'} restored.`);
            this.trigger('restored', result);
            this.close();
        } catch (error) {
            this.enableButton('restore');
            throw error;
        } finally {
            Espo.Ui.notify(false);
        }
    }
});
