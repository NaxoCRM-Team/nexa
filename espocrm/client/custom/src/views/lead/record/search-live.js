define('custom:views/lead/record/search-live', ['views/record/search'], Dep => class extends Dep {
    setup() {
        super.setup();
        this.addHandler('input', 'input[data-name="textFilter"]', 'queueLiveSearch');
        this.listenTo(this.collection, 'request', () => this.setState(true));
        this.listenTo(this.collection, 'sync error', () => this.setState(false));
        this.once('remove', () => window.clearTimeout(this.liveSearchTimer));
    }

    afterRender() {
        super.afterRender();
        this.element?.classList.add('nexa-lead-live-search');
        this.$textFilter?.attr('autocomplete', 'off').attr('aria-label', 'Search Leads as you type');
        if (!this.liveSearchStatus?.isConnected) {
            this.liveSearchStatus = document.createElement('span');
            this.liveSearchStatus.className = 'sr-only';
            this.liveSearchStatus.setAttribute('role', 'status');
            this.liveSearchStatus.setAttribute('aria-live', 'polite');
            this.element?.append(this.liveSearchStatus);
        }
    }

    queueLiveSearch(event) {
        if (event.isComposing || event.originalEvent?.isComposing) return;
        this.filterLoadedRows(event.target.value);
        window.clearTimeout(this.liveSearchTimer);
        this.liveSearchTimer = window.setTimeout(() => this.runLiveSearch(), 320);
    }

    filterLoadedRows(value) {
        const normalize = text => String(text || '').normalize('NFKD')
            .replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase();
        const query = normalize(value.trim());
        const rows = document.querySelectorAll('#main .nexa-lead-scroll-list tbody tr.list-row');
        let visible = 0;
        rows.forEach(row => {
            const matches = !query || normalize(row.textContent).includes(query);
            row.classList.toggle('nexa-lead-row-filtered', !matches);
            row.setAttribute('aria-hidden', String(!matches));
            if (matches) visible++;
        });
        if (this.liveSearchStatus) this.liveSearchStatus.textContent = `${visible} loaded Lead${visible === 1 ? '' : 's'} match.`;
    }

    search() {
        window.clearTimeout(this.liveSearchTimer);
        return super.search();
    }

    runLiveSearch() {
        if (!this.isRendered()) return;
        this.fetch();
        this.updateSearch();
        this.collection.abortLastFetch();
        this.collection.where = this.searchManager.getWhere();
        this.collection.offset = 0;
        this.collection.fetch({reset: true, maxSize: this.collection.maxSize}).catch(() => {});
        this.controlResetButtonVisibility();
        this.isSearchedWithAdvancedFilter = this.hasAdvancedFilter();
    }

    setState(loading) {
        if (!this.liveSearchStatus) return;
        this.liveSearchStatus.textContent = loading ? 'Checking all Leads.' :
            `${this.collection.length} Lead${this.collection.length === 1 ? '' : 's'} shown.`;
    }
});
