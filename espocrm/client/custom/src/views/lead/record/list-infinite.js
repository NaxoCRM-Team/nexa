define('custom:views/lead/record/list-infinite', ['views/record/list', 'custom:table-inline-editor'], (Dep, TableInlineEditor) => class extends Dep {
    setup() {
        super.setup();
        this.inlineEditor = new TableInlineEditor(this, 'Lead', {
            rating: {type: 'dropdown'},
            lifecycleStage: {type: 'dropdown'},
            marketingStatus: {type: 'dropdown'},
        });
        this.inlineEditor.setup();
        this.listenTo(this.collection, 'sync reset', () => this.scheduleScrollCheck());
        this.listenTo(this.collection, 'error', () => { this.loadingNextPage = false; });
        this.once('remove', () => {
            this.observer?.disconnect();
            this.releaseScrollContainer();
        });
    }

    afterRender() {
        const result = super.afterRender();
        this.element?.querySelector('table')?.classList.add('nexa-crm-table');
        this.bindScrollContainer();
        this.inlineEditor.decorate();
        this.observe();
        this.setupColumnResize();
        this.scheduleScrollCheck();
        return result;
    }

    observe() {
        if (this.observer || !this.element) return;
        this.observer = new MutationObserver(() => {
            this.element?.querySelector('table')?.classList.add('nexa-crm-table');
            this.bindScrollContainer();
            this.inlineEditor.decorate();
            this.setupColumnResize();
            this.scheduleScrollCheck();
        });
        this.observer.observe(this.element, {childList: true, subtree: true});
    }

    bindScrollContainer() {
        const container = this.element?.matches('.list') ? this.element : this.element?.querySelector('.list');
        if (!container || container === this.scrollContainer) return;
        this.releaseScrollContainer();
        this.scrollContainer = container;
        container.classList.add('nexa-lead-scroll-list');
        container.setAttribute('tabindex', '0');
        container.setAttribute('aria-label', 'Lead records. More records load as you scroll.');
        this.scrollHandler = () => this.loadNextPageWhenNeeded();
        container.addEventListener('scroll', this.scrollHandler, {passive: true});
    }

    releaseScrollContainer() {
        this.scrollContainer?.removeEventListener('scroll', this.scrollHandler);
        this.scrollContainer = null;
        this.scrollHandler = null;
    }

    scheduleScrollCheck() { window.requestAnimationFrame(() => this.loadNextPageWhenNeeded()); }

    loadNextPageWhenNeeded() {
        const container = this.scrollContainer;
        if (!container || this.loadingNextPage || this.collection.isBeingFetched() || !this.collection.hasMore()) return;
        if (container.scrollHeight - container.scrollTop - container.clientHeight > 180) return;
        this.loadingNextPage = true;
        this.showMoreRecords({skipNotify: true}, null, null, null, () => {
            this.loadingNextPage = false;
            this.announceLoadedPage();
            this.scheduleScrollCheck();
        });
    }

    announceLoadedPage() {
        let status = this.element?.querySelector('.nexa-lead-scroll-status');
        if (!status && this.element) {
            status = document.createElement('span');
            status.className = 'sr-only nexa-lead-scroll-status';
            status.setAttribute('role', 'status');
            status.setAttribute('aria-live', 'polite');
            this.element.prepend(status);
        }
        if (status) status.textContent = `${this.collection.length} Leads loaded.`;
    }

    setupColumnResize() {
        const table = this.element?.querySelector('table.nexa-crm-table');
        if (!table || table === this.resizeTable) return;
        this.resizeTable = table;
        const storageKey = 'nexaListColumnWidths:Lead';
        const widths = this.getStorage().get('state', storageKey) || {};
        Object.entries(widths).forEach(([name, width]) => this.applyWidth(table, name, width));
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
                const startX = event.pageX;
                const startWidth = th.getBoundingClientRect().width;
                const move = moveEvent => this.applyWidth(table, name, Math.max(70, startWidth + moveEvent.pageX - startX));
                const finish = () => {
                    document.removeEventListener('mousemove', move);
                    document.removeEventListener('mouseup', finish);
                    widths[name] = Math.round(th.getBoundingClientRect().width);
                    this.getStorage().set('state', storageKey, widths);
                };
                document.addEventListener('mousemove', move);
                document.addEventListener('mouseup', finish);
            });
        });
    }

    applyWidth(table, name, width) {
        const px = `${Math.round(width)}px`;
        table.querySelectorAll(`th[data-name="${name}"], td[data-name="${name}"]`).forEach(cell => { cell.style.width = px; });
    }
});
