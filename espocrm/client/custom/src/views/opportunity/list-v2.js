define('custom:views/opportunity/list-v2', ['views/list'], Dep => class extends Dep {
    searchView = 'custom:views/opportunity/record/search-live';
    recordView = 'custom:views/opportunity/record/list-infinite';

    setup() {
        super.setup();
        this.once('remove', () => {
            this.controlsObserver?.disconnect();
            this.element?.classList.remove('nexa-opportunity-list-page');
        });
    }

    prepareRecordViewOptions(options) {
        super.prepareRecordViewOptions(options);
        options.pagination = false;
        options.showMore = true;
    }

    afterRender() {
        const result = super.afterRender();
        this.element?.classList.add('nexa-opportunity-list-page');
        this.decorateControls();
        this.observeControls();
        return result;
    }

    observeControls() {
        if (!this.element || this.controlsObserver) return;
        this.controlsObserver = new MutationObserver(() => this.decorateControls());
        this.controlsObserver.observe(this.element.parentElement || this.element, {childList: true, subtree: true});
    }

    decorateControls() {
        const create = this.element?.querySelector('.page-header [data-action="create"]');
        if (create && !create.classList.contains('nexa-opportunity-create-button')) {
            create.classList.add('nexa-opportunity-create-button');
            create.innerHTML = '<span class="fas fa-plus" aria-hidden="true"></span><span>New Opportunity</span>';
        }

        const filter = this.element?.parentElement?.querySelector('.nexa-opportunity-live-search .add-filter-button');
        if (filter && !filter.classList.contains('nexa-opportunity-filter-button')) {
            filter.classList.add('nexa-opportunity-filter-button');
            filter.setAttribute('aria-label', 'Choose Opportunity filters');
            filter.title = 'Choose filters';
            filter.innerHTML = '<span class="fas fa-filter" aria-hidden="true"></span><span>Filters</span><span class="caret" aria-hidden="true"></span>';
        }

        const total = this.element?.querySelector('.total-count');
        if (total && !total.querySelector('.nexa-total-label')) total.insertAdjacentHTML('afterbegin', '<span class="nexa-total-label">Total opportunities:</span>');
        const listContainer = this.element?.querySelector('.list-container');
        if (listContainer && !listContainer.querySelector('.nexa-sales-shortcuts')) {
            listContainer.insertAdjacentHTML('afterbegin', `<nav class="nexa-sales-shortcuts" aria-label="Sales workspaces">
                <a href="#NexaSales/pipelines"><span class="fas fa-project-diagram" aria-hidden="true"></span>Pipelines</a>
                <a href="#NexaSales/forecasts"><span class="fas fa-chart-bar" aria-hidden="true"></span>Forecasts</a>
                <a href="#NexaSales/products"><span class="fas fa-box" aria-hidden="true"></span>Products & Quotes</a>
            </nav>`);
        }
    }
});
