define('custom:views/contact/record/edit-v2', ['custom:views/contact/record/edit'], Dep => {
    /**
     * Versioned entry point prevents older browser-cached Contact form modules
     * from restoring the legacy standalone title field.
     */
    return class extends Dep {
        setup() {
            super.setup();
            this.hideField('title', true);
        }
    };
});
