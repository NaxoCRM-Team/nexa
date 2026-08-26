define('custom:views/lead/list-v2', ['views/list'], Dep => class extends Dep {
    searchView = 'custom:views/lead/record/search-live';
    recordView = 'custom:views/lead/record/list-infinite';

    setup() {
        super.setup();
        this.once('remove', () => {
            this.controlsObserver?.disconnect();
            this.listElement?.classList.remove('nexa-lead-list-page');
            this.listElement = null;
        });
    }

    /** Leads use one stable table viewport and load the next page on demand. */
    prepareRecordViewOptions(options) {
        super.prepareRecordViewOptions(options);
        options.pagination = false;
        options.showMore = true;
    }

    afterRender() {
        const result = super.afterRender();
        this.listElement = this.element;
        this.listElement?.classList.add('nexa-lead-list-page');
        this.decorateControls();
        this.renderScopeTabs();
        this.observeControls();
        return result;
    }

    observeControls() {
        this.controlsObserver?.disconnect();
        if (!this.element) return;
        this.controlsObserver = new MutationObserver(() => {
            this.decorateControls();
            this.renderScopeTabs();
        });
        this.controlsObserver.observe(this.element, {childList: true, subtree: true});
    }

    decorateControls() {
        const root = this.element;
        if (!root) return;

        root.parentElement?.querySelector('.nexa-lead-live-search input[data-name="textFilter"]')
            ?.setAttribute('placeholder', 'Search leads by name, company, email or phone');

        const createButton = root.querySelector('.page-header .header-buttons [data-action="create"], .page-header .header-buttons [data-name="create"]');
        if (createButton && !createButton.classList.contains('nexa-lead-create-button')) {
            createButton.classList.add('nexa-lead-create-button');
            createButton.innerHTML = '<span class="fas fa-plus" aria-hidden="true"></span><span>New Lead</span>';
        }

        const columnButton = root.querySelector('.settings-container .dropdown-toggle');
        if (columnButton && !columnButton.classList.contains('nexa-lead-column-selector')) {
            columnButton.classList.add('nexa-lead-column-selector');
            columnButton.setAttribute('aria-label', 'Choose visible Lead columns');
            columnButton.title = 'Choose visible columns';
            columnButton.innerHTML = '<span class="fas fa-columns" aria-hidden="true"></span><span>Columns</span><span class="caret" aria-hidden="true"></span>';
        }

        const total = root.querySelector('.total-count');
        if (total && !total.querySelector('.nexa-total-label')) {
            const label = document.createElement('span');
            label.className = 'nexa-total-label';
            label.textContent = 'Total leads:';
            total.prepend(label);
        }
    }

    renderScopeTabs() {
        const search = this.element?.parentElement?.querySelector('.nexa-lead-live-search') ||
            this.element?.querySelector('.nexa-lead-live-search');
        if (!search || search.previousElementSibling?.classList.contains('nexa-lead-scope-tabs')) return;

        const active = this._primaryFilter === 'createdByMe' ||
            this.options?.params?.primaryFilter === 'createdByMe' ||
            decodeURIComponent(window.location.hash).includes('primaryFilter=createdByMe');
        const tabs = document.createElement('nav');
        tabs.className = 'nexa-lead-scope-tabs';
        tabs.setAttribute('aria-label', 'Lead list scope');
        tabs.innerHTML = `
            <a href="#Lead/list/primaryFilter=createdByMe" ${active ? 'aria-current="page"' : ''}>My Leads</a>
            <a href="#Lead" ${active ? '' : 'aria-current="page"'}>All Leads</a>`;
        search.before(tabs);
    }
});
