define('custom:views/opportunity/record/search-live', ['views/record/search'], Dep => class extends Dep {
    setup() {
        super.setup();
        this.addHandler('input', 'input[data-name="textFilter"]', 'queueSearch');
        this.once('remove', () => window.clearTimeout(this.timer));
    }
    afterRender() {
        super.afterRender();
        this.element?.classList.add('nexa-opportunity-live-search');
        this.$textFilter?.attr('placeholder', 'Search opportunities, accounts, stages or next steps').attr('autocomplete', 'off');
    }
    queueSearch(event) {
        if (event.isComposing || event.originalEvent?.isComposing) return;
        window.clearTimeout(this.timer);
        this.timer = window.setTimeout(() => {
            if (!this.isRendered()) return;
            this.fetch(); this.updateSearch(); this.collection.abortLastFetch();
            this.collection.where = this.searchManager.getWhere(); this.collection.offset = 0;
            this.collection.fetch({reset: true, maxSize: this.collection.maxSize}).catch(() => {});
            this.controlResetButtonVisibility();
        }, 320);
    }
});
