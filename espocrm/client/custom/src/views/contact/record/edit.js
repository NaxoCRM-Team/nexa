define('custom:views/contact/record/edit', ['views/record/edit'], Dep => {
    /**
     * Contact creation keeps the frequent actions visible and leaves secondary
     * actions, such as Save & New, in Espo's existing overflow menu.
     */
    return class extends Dep {
        buttonList = [
            {
                name: 'save',
                style: 'success',
                html: '<span>Save</span><span class="fas fa-check" aria-hidden="true"></span>',
                title: 'Ctrl+Enter',
            },
            {
                name: 'saveAndContinueEditing',
                style: 'success',
                html: '<span>Save &amp; Continue</span><span class="far fa-save" aria-hidden="true"></span>',
                title: 'Ctrl+S',
            },
            {
                name: 'cancel',
                html: '<span>Cancel</span><span class="fas fa-times" aria-hidden="true"></span>',
                title: 'Esc',
            },
        ];

        saveAndContinueEditingAction = false;

        setup() {
            super.setup();

            // The Accounts relationship already owns its per-account Title input.
            this.hideField('title', true);
        }
    };
});
