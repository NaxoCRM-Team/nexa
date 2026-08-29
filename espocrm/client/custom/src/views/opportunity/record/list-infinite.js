define('custom:views/opportunity/record/list-infinite', ['views/record/list', 'custom:table-inline-editor'], (Dep, TableInlineEditor) => class extends Dep {
    setup() {
        super.setup();
        this.inlineEditor = new TableInlineEditor(this, 'Opportunity', {stage: {type: 'dropdown'}, forecastCategory: {type: 'dropdown'}, closeDate: {type: 'text'}, nextStep: {type: 'text'}});
        this.inlineEditor.setup();
        this.listenTo(this.collection, 'sync reset', () => requestAnimationFrame(() => this.loadNext()));
        this.once('remove', () => this.release());
    }
    afterRender() {
        const result = super.afterRender();
        this.element?.querySelector('table')?.classList.add('nexa-crm-table');
        this.bind(); this.inlineEditor.decorate();
        return result;
    }
    bind() {
        const node = this.element?.matches('.list') ? this.element : this.element?.querySelector('.list');
        if (!node || node === this.scroller) return;
        this.release(); this.scroller = node; node.classList.add('nexa-opportunity-scroll-list'); node.tabIndex = 0;
        this.scrollHandler = () => this.loadNext(); node.addEventListener('scroll', this.scrollHandler, {passive: true});
    }
    release() { this.scroller?.removeEventListener('scroll', this.scrollHandler); this.scroller = null; }
    loadNext() {
        const node = this.scroller;
        if (!node || this.loading || this.collection.isBeingFetched() || !this.collection.hasMore() || node.scrollHeight - node.scrollTop - node.clientHeight > 180) return;
        this.loading = true;
        this.showMoreRecords({skipNotify: true}, null, null, null, () => { this.loading = false; requestAnimationFrame(() => this.loadNext()); });
    }
});
