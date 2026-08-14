define('custom:views/contact/modals/export-delete-confirmation', ['views/modal'], Dep => class extends Dep {
    template = 'custom:contact/modals/export-delete-confirmation';
    cssName = 'nexa-export-delete-confirmation-modal';
    className = 'dialog nexa-export-delete-dialog';
    noFullHeight = true;

    setup() {
        this.headerText = 'Delete export?';
        this.addButton({name: 'delete', label: 'Delete export', style: 'danger'});
        this.addButton({name: 'cancel', label: 'Cancel'});
    }

    data() {
        return {name: this.options.name || 'this export'};
    }

    actionDelete() {
        this.trigger('confirm');
        this.close();
    }
});
