define('custom:views/contact/record/edit-v3', ['custom:views/contact/record/edit'], Dep => {
    /**
     * A versioned module ensures existing sessions load the current create
     * form rules instead of an older browser-cached record view.
     */
    return class extends Dep {
        setup() {
            super.setup();

            this.hideField('title', true);

            if (!this.model.isNew()) {
                return;
            }

            ['department', 'leadScore', 'lastWebsiteVisitAt'].forEach(field => {
                this.hideField(field, true);
            });
        }
    };
});
