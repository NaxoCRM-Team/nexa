define('custom:views/contact/record/search-live-v2', ['views/record/search'], Dep => class extends Dep {
    setup() {
        super.setup();

        this.addHandler('input', 'input[data-name="textFilter"]', 'queueLiveSearch');
        this.listenTo(this.collection, 'request', () => this.setLiveSearchState(true));
        this.listenTo(this.collection, 'sync error', () => this.setLiveSearchState(false));
        this.once('remove', () => window.clearTimeout(this.liveSearchTimer));
    }

    afterRender() {
        super.afterRender();

        this.element?.classList.add('nexa-contact-live-search');
        this.$textFilter
            ?.attr('autocomplete', 'off')
            .attr('aria-label', 'Search contacts as you type');

        if (!this.liveSearchStatus?.isConnected) {
            this.liveSearchStatus = document.createElement('span');
            this.liveSearchStatus.className = 'sr-only nexa-contact-search-status';
            this.liveSearchStatus.setAttribute('role', 'status');
            this.liveSearchStatus.setAttribute('aria-live', 'polite');
            this.element?.append(this.liveSearchStatus);
        }
    }

    queueLiveSearch(event) {
        if (event.isComposing || event.originalEvent?.isComposing) return;

        this.filterVisibleRows(event.target.value);
        window.clearTimeout(this.liveSearchTimer);
        this.liveSearchTimer = window.setTimeout(() => this.runLiveSearch(), 320);
    }

    filterVisibleRows(value) {
        const normalize = text => String(text || '')
            .normalize('NFKD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLocaleLowerCase();
        const query = normalize(value.trim());
        const rows = document.querySelectorAll('#main .nexa-contact-scroll-list tbody tr.list-row');
        let visibleCount = 0;

        rows.forEach(row => {
            const matches = !query || normalize(row.textContent).includes(query);
            row.classList.toggle('nexa-contact-row-filtered', !matches);
            row.setAttribute('aria-hidden', String(!matches));
            if (matches) visibleCount++;
        });

        if (this.liveSearchStatus) {
            this.liveSearchStatus.textContent = `${visibleCount} loaded contact${visibleCount === 1 ? '' : 's'} match.`;
        }
    }

    search() {
        window.clearTimeout(this.liveSearchTimer);

        return super.search();
    }

    runLiveSearch() {
        if (!this.isRendered()) return;

        // Reuse Espo's search manager so the request retains all central ACL,
        // tenant and service scoping instead of introducing a separate endpoint.
        this.fetch();
        this.updateSearch();
        this.updateCollectionSilently();
        this.controlResetButtonVisibility();
        this.isSearchedWithAdvancedFilter = this.hasAdvancedFilter();
    }

    updateCollectionSilently() {
        this.collection.abortLastFetch();
        this.collection.where = this.searchManager.getWhere();
        this.collection.offset = 0;

        // Keep current rows visible until the complete server-filtered page is
        // ready. Resetting on success prevents stale non-matching models.
        this.collection.fetch({
            reset: true,
            maxSize: this.collection.maxSize,
        }).catch(() => {});
    }

    setLiveSearchState(isLoading) {
        if (!this.liveSearchStatus) return;

        this.liveSearchStatus.textContent = isLoading ?
            'Checking all contacts.' :
            `${this.collection.length} contact${this.collection.length === 1 ? '' : 's'} shown.`;
    }
});
