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
        this.bindScrollContainer(); this.inlineEditor.decorate(); this.observe(); this.scheduleScrollCheck(); this.setupColumnResize(); return result;
    }
    observe() {
        if (this.observer || !this.element) return;
        this.observer = new MutationObserver(() => { this.element?.querySelector('table')?.classList.add('nexa-crm-table','nexa-case-table'); this.bindScrollContainer(); this.inlineEditor.decorate(); this.scheduleScrollCheck(); this.setupColumnResize(); });
        this.observer.observe(this.element, {childList:true,subtree:true});
    }
    setupColumnResize() {
        const table = this.element?.querySelector('table.nexa-crm-table');
        if (!table) return;

        const storageKey = `nexaListColumnWidths:${this.entityType}`;
        const savedWidths = this.getStorage().get('state', storageKey) || {};
        if (table !== this.resizeTable) this.freezeColumnWidths(table);
        Object.entries(savedWidths).forEach(([name, width]) => this.applyColumnWidth(table, name, width));
        if (table === this.resizeTable) return;
        this.resizeTable = table;

        table.querySelectorAll('thead > tr > th[data-name]').forEach(th => {
            const name = th.dataset.name;
            if (name === 'r-checkbox' || th.classList.contains('action-cell')) return;

            const handle = document.createElement('span');
            handle.className = 'nexa-col-resizer';
            handle.setAttribute('aria-hidden', 'true');
            th.style.position = 'relative';
            th.append(handle);
            handle.addEventListener('mousedown', event => {
                event.preventDefault();
                event.stopPropagation();
                this.startColumnResize(table, th, name, event, storageKey, savedWidths);
            });
        });
    }
    startColumnResize(table, th, name, event, storageKey, widths) {
        const startX = event.pageX;
        const startWidth = th.getBoundingClientRect().width;
        const handle = th.querySelector('.nexa-col-resizer');
        handle?.classList.add('is-resizing');
        document.body.classList.add('nexa-col-resizing');

        const move = moveEvent => this.applyColumnWidth(
            table,
            name,
            Math.max(60, startWidth + moveEvent.pageX - startX)
        );
        const finish = () => {
            document.removeEventListener('mousemove', move);
            document.removeEventListener('mouseup', finish);
            handle?.classList.remove('is-resizing');
            document.body.classList.remove('nexa-col-resizing');
            widths[name] = Math.round(th.getBoundingClientRect().width);
            this.getStorage().set('state', storageKey, widths);
        };

        document.addEventListener('mousemove', move);
        document.addEventListener('mouseup', finish);
    }
    applyColumnWidth(table, name, width) {
        const px = `${Math.round(width)}px`;
        table.querySelectorAll(`thead > tr > th[data-name="${name}"], tbody > tr > td[data-name="${name}"]`)
            .forEach(cell => { cell.style.width = px; });
        this.recalculateTableWidth(table);
    }
    freezeColumnWidths(table) {
        table.querySelectorAll('thead > tr > th').forEach(th => {
            const width = Math.round(th.getBoundingClientRect().width);
            th.style.width = `${width}px`;
            if (th.dataset.name) {
                table.querySelectorAll(`tbody > tr > td[data-name="${th.dataset.name}"]`)
                    .forEach(td => { td.style.width = `${width}px`; });
            }
        });
        this.recalculateTableWidth(table);
    }
    recalculateTableWidth(table) {
        let total = 0;
        table.querySelectorAll('thead > tr > th').forEach(th => { total += th.getBoundingClientRect().width; });
        table.style.width = `${Math.round(total)}px`;
    }
    bindScrollContainer() {
        const container = this.element?.matches('.list') ? this.element : this.element?.querySelector('.list');
        if (!container || container === this.scrollContainer) return;
        this.releaseScrollContainer(); this.scrollContainer = container; container.classList.add('nexa-case-scroll-list');
        container.tabIndex = 0; container.setAttribute('aria-label','Case records. Scroll vertically and horizontally; more records load near the bottom.');
        this.scrollHandler = () => this.loadNextPageWhenNeeded(); container.addEventListener('scroll',this.scrollHandler,{passive:true});
    }
    releaseScrollContainer() { this.scrollContainer?.removeEventListener('scroll',this.scrollHandler); this.scrollContainer=null; this.scrollHandler=null; }
    scheduleScrollCheck() { window.requestAnimationFrame(() => this.loadNextPageWhenNeeded()); }
    loadNextPageWhenNeeded() {
        const c=this.scrollContainer; if(!c||this.loadingNextPage||this.collection.isBeingFetched()||!this.collection.hasMore()) return;
        if(c.scrollHeight-c.scrollTop-c.clientHeight>180) return; this.loadingNextPage=true;
        this.showMoreRecords({skipNotify:true},null,null,null,()=>{this.loadingNextPage=false;this.announceLoadedPage();this.scheduleScrollCheck();});
    }
    announceLoadedPage() {
        let status = this.element?.querySelector('.nexa-case-scroll-status');
        if (!status && this.element) {
            status = document.createElement('span');
            status.className = 'sr-only nexa-case-scroll-status';
            status.setAttribute('role', 'status');
            status.setAttribute('aria-live', 'polite');
            this.element.prepend(status);
        }
        if (status) status.textContent = `${this.collection.length} cases loaded.`;
    }
});
