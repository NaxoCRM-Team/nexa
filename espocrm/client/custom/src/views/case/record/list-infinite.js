define('custom:views/case/record/list-infinite', ['views/record/list', 'custom:table-inline-editor'], (Dep, TableInlineEditor) => class extends Dep {
    setup() {
        super.setup();
        this.inlineEditor = new TableInlineEditor(this, 'Case', {status:{type:'dropdown'},priority:{type:'dropdown'},category:{type:'dropdown'}});
        this.inlineEditor.setup(); this.listenTo(this.collection, 'sync reset', () => this.scheduleScrollCheck());
        this.listenTo(this.collection, 'error', () => { this.loadingNextPage = false; });
        this.once('remove', () => { this.observer?.disconnect(); this.releaseScrollContainer(); });
    }
    afterRender() {
        const result = super.afterRender(); this.element?.querySelector('table')?.classList.add('nexa-crm-table','nexa-case-table');
        this.bindScrollContainer(); this.inlineEditor.decorate(); this.observe(); this.scheduleScrollCheck(); return result;
    }
    observe() {
        if (this.observer || !this.element) return;
        this.observer = new MutationObserver(() => { this.element?.querySelector('table')?.classList.add('nexa-crm-table','nexa-case-table'); this.bindScrollContainer(); this.inlineEditor.decorate(); this.scheduleScrollCheck(); });
        this.observer.observe(this.element, {childList:true,subtree:true});
    }
    bindScrollContainer() {
        const container = this.element?.matches('.list') ? this.element : this.element?.querySelector('.list');
        if (!container || container === this.scrollContainer) return;
        this.releaseScrollContainer(); this.scrollContainer = container; container.classList.add('nexa-case-scroll-list');
        container.tabIndex = 0; container.setAttribute('aria-label','Case records. More records load as you scroll.');
        this.scrollHandler = () => this.loadNextPageWhenNeeded(); container.addEventListener('scroll',this.scrollHandler,{passive:true});
    }
    releaseScrollContainer() { this.scrollContainer?.removeEventListener('scroll',this.scrollHandler); this.scrollContainer=null; this.scrollHandler=null; }
    scheduleScrollCheck() { window.requestAnimationFrame(() => this.loadNextPageWhenNeeded()); }
    loadNextPageWhenNeeded() {
        const c=this.scrollContainer; if(!c||this.loadingNextPage||this.collection.isBeingFetched()||!this.collection.hasMore()) return;
        if(c.scrollHeight-c.scrollTop-c.clientHeight>180) return; this.loadingNextPage=true;
        this.showMoreRecords({skipNotify:true},null,null,null,()=>{this.loadingNextPage=false;this.scheduleScrollCheck();});
    }
});
