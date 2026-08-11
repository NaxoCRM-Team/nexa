define('custom:views/contact/record/edit-v2', ['custom:views/contact/record/edit'], Dep => {
    /**
     * Versioned entry point prevents older browser-cached Contact form modules
     * from restoring the legacy standalone title field.
     */
    return class extends Dep {
        setup() {
            super.setup();
            this.hideField('title', true);

            if (!this.model.isNew()) {
                return;
            }

            // These fields are populated later by enrichment or automation.
            ['department', 'leadScore', 'lastWebsiteVisitAt'].forEach(field => {
                this.hideField(field, true);
            });
        }
    };
});
