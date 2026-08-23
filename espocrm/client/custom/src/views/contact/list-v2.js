define('custom:views/contact/list-v2', ['views/list'], Dep => class extends Dep {
    searchView = 'custom:views/contact/record/search-live-v2';
    recordView = 'custom:views/contact/record/list-infinite-v2';

    setup() {
        super.setup();
        this.handleContactTrashChanged = () => this.refreshRestoreRecordsButton();
        document.addEventListener('nexa:contact-trash-changed', this.handleContactTrashChanged);

        this.once('remove', () => {
            this.contactControlsObserver?.disconnect();
            document.removeEventListener('nexa:contact-trash-changed', this.handleContactTrashChanged);

            // Espo can reuse the main content node between list and record routes.
            // Remove the list-only scroll lock before a Contact record is rendered.
            this.contactListElement?.classList.remove('nexa-contact-list-page');
            this.contactListElement = null;
        });
    }

    /**
     * Contact lists use incremental scrolling, while the parent view continues
     * to own the scoped collection, filters, sorting and bulk actions.
     */
    prepareRecordViewOptions(options) {
        super.prepareRecordViewOptions(options);
        options.pagination = false;
        options.showMore = true;
    }

    afterRender() {
        const result = super.afterRender();
        this.decorateAccountFilter();
        this.contactListElement = this.element;
        this.contactListElement?.classList.add('nexa-contact-list-page');
        this.decorateContactControls();
        this.observeContactControls();
        this.refreshRestoreRecordsButton();

        return result;
    }

    decorateAccountFilter() {
        const accountId = this.options?.params?.accountId;
        if (!accountId || !this.element || this.element.querySelector('.nexa-contact-account-filter')) return;

        const accountName = this.options.params.accountName || 'Selected account';
        const band = document.createElement('div');
        band.className = 'nexa-contact-account-filter';
        band.innerHTML = [
            '<span class="fas fa-building" aria-hidden="true"></span>',
            '<span>Showing contacts for <strong></strong></span>',
            '<a class="btn btn-default btn-sm" href="#Contact">Clear account filter</a>',
        ].join('');
        band.querySelector('strong').textContent = accountName;

        const search = this.element.querySelector('.search-container');
        search ? search.after(band) : this.element.prepend(band);
    }

    observeContactControls() {
        if (!this.element) return;

        this.contactControlsObserver?.disconnect();
        this.contactControlsObserver = new MutationObserver(() => {
            this.decorateContactControls();
            this.decorateAccountFilter();
        });
        this.contactControlsObserver.observe(this.element, {childList: true, subtree: true});
    }

    decorateContactControls() {
        const root = this.element;
        if (!root) return;

        const searchInput = root.parentElement?.querySelector(
            '.nexa-contact-live-search input[data-name="textFilter"]'
        );
        searchInput?.setAttribute('placeholder', 'Search contacts');

        const columnButton = root.querySelector('.settings-container .dropdown-toggle');
        const settingsContainer = root.querySelector('.settings-container');
        if (settingsContainer && !root.querySelector('.nexa-contact-import-button')) {
            const importButton = document.createElement('a');
            importButton.className = 'btn btn-default nexa-contact-import-button';
            importButton.href = '#Contact/import';
            importButton.innerHTML = '<span class="fas fa-file-import" aria-hidden="true"></span><span>Import</span>';
            importButton.setAttribute('aria-label', 'Import contacts');
            importButton.title = 'Import contacts';
            settingsContainer.after(importButton);
        }

        this.renderRestoreRecordsButton();

        if (columnButton && !columnButton.classList.contains('nexa-column-selector')) {
            columnButton.classList.add('nexa-column-selector');
            columnButton.setAttribute('aria-label', 'Choose visible contact columns');
            columnButton.setAttribute('title', 'Choose visible columns');
            columnButton.innerHTML = [
                '<span class="fas fa-columns" aria-hidden="true"></span>',
                '<span>Columns</span>',
                '<span class="caret" aria-hidden="true"></span>',
            ].join('');
        }

        const total = root.querySelector('.total-count');
        if (total) {
            if (!total.querySelector('.nexa-total-label')) {
                const label = document.createElement('span');
                label.className = 'nexa-total-label';
                label.textContent = 'Total contacts:';
                total.prepend(label);
            }

            total.setAttribute('aria-label', `Total contacts: ${total.querySelector('.total-count-span')?.textContent || 0}`);
        }
    }

    async refreshRestoreRecordsButton() {
        if (!this.getUser().isAdmin() || this.restoreRecordsLoading) {
            this.deletedContactTotal = 0;
            this.renderRestoreRecordsButton();
            return;
        }

        this.restoreRecordsLoading = true;

        try {
            const result = await Espo.Ajax.getRequest('Nexa/contact/trash');
            this.deletedContactTotal = Number(result.total) || 0;
        } catch (error) {
            // A failed count check must never expose an administration control.
            this.deletedContactTotal = 0;
        } finally {
            this.restoreRecordsLoading = false;
            this.renderRestoreRecordsButton();
        }
    }

    renderRestoreRecordsButton() {
        const root = this.element;
        if (!root) return;

        const headerButtons = root.querySelector('.page-header .header-buttons') ||
            root.parentElement?.querySelector('.page-header .header-buttons');
        const existing = root.querySelector('.nexa-contact-trash-button') ||
            root.parentElement?.querySelector('.nexa-contact-trash-button');
        const createButton = headerButtons?.querySelector('[data-action="create"], [data-name="create"]');

        createButton?.classList.add('nexa-contact-header-action');

        if (!headerButtons || !this.getUser().isAdmin() || !this.deletedContactTotal) {
            existing?.remove();
            return;
        }

        if (existing) return;

        const trashButton = document.createElement('a');
        trashButton.className = 'btn btn-default nexa-contact-header-action nexa-contact-trash-button';
        trashButton.href = '#Contact/trash';
        trashButton.innerHTML = '<span class="fas fa-undo-alt" aria-hidden="true"></span><span>Restore records</span>';
        trashButton.setAttribute('aria-label', `Restore deleted contact records (${this.deletedContactTotal})`);
        trashButton.title = `${this.deletedContactTotal} deleted ${this.deletedContactTotal === 1 ? 'record' : 'records'}`;

        createButton ? createButton.after(trashButton) : headerButtons.appendChild(trashButton);
    }

});
