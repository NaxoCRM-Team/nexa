define('custom:views/account/list-v2', ['views/list'], Dep => class extends Dep {
    searchView = 'custom:views/account/record/search-live-v2';
    recordView = 'custom:views/account/record/list-infinite-v2';

    setup() {
        super.setup();
        this.handleAccountTrashChanged = () => this.refreshRestoreRecordsButton();
        document.addEventListener('nexa:account-trash-changed', this.handleAccountTrashChanged);

        this.once('remove', () => {
            this.accountControlsObserver?.disconnect();
            document.removeEventListener('nexa:account-trash-changed', this.handleAccountTrashChanged);
            this.accountListElement?.classList.remove('nexa-account-list-page');
            this.accountListElement = null;
        });
    }

    /** Keep high-volume company records inside one stable, scrollable workspace. */
    prepareRecordViewOptions(options) {
        super.prepareRecordViewOptions(options);
        options.pagination = false;
        options.showMore = true;
    }

    afterRender() {
        const result = super.afterRender();
        this.accountListElement = this.element;
        this.accountListElement?.classList.add('nexa-account-list-page');
        this.decorateAccountControls();
        this.renderAccountScopeTabs();
        this.observeAccountControls();
        this.refreshRestoreRecordsButton();

        return result;
    }

    observeAccountControls() {
        if (!this.element) return;

        this.accountControlsObserver?.disconnect();
        this.accountControlsObserver = new MutationObserver(() => this.decorateAccountControls());
        this.accountControlsObserver.observe(this.element, {childList: true, subtree: true});
    }

    decorateAccountControls() {
        const root = this.element;
        if (!root) return;

        root.parentElement?.querySelector('.nexa-account-live-search input[data-name="textFilter"]')
            ?.setAttribute('placeholder', 'Search accounts');

        const columnButton = root.querySelector('.settings-container .dropdown-toggle');
        const settingsContainer = root.querySelector('.settings-container');
        if (settingsContainer && !root.querySelector('.nexa-account-import-button')) {
            const importButton = document.createElement('a');
            importButton.className = 'btn btn-default nexa-account-import-button';
            importButton.href = '#Account/import';
            importButton.innerHTML = '<span class="fas fa-file-import" aria-hidden="true"></span><span>Import</span>';
            importButton.setAttribute('aria-label', 'Import accounts');
            importButton.title = 'Import accounts';
            settingsContainer.after(importButton);
        }

        this.renderRestoreRecordsButton();
        if (columnButton && !columnButton.classList.contains('nexa-column-selector')) {
            columnButton.classList.add('nexa-column-selector');
            columnButton.setAttribute('aria-label', 'Choose visible account columns');
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
                label.textContent = 'Total accounts:';
                total.prepend(label);
            }

            total.setAttribute('aria-label', `Total accounts: ${total.querySelector('.total-count-span')?.textContent || 0}`);
        }
    }

    renderAccountScopeTabs() {
        const search = this.element?.parentElement?.querySelector('.nexa-account-live-search') ||
            this.element?.querySelector('.nexa-account-live-search');
        if (!search || search.previousElementSibling?.classList.contains('nexa-account-scope-tabs')) return;

        const active = this._primaryFilter === 'createdByMe' ||
            this.options?.params?.primaryFilter === 'createdByMe' ||
            decodeURIComponent(window.location.hash).includes('primaryFilter=createdByMe');
        const tabs = document.createElement('nav');
        tabs.className = 'nexa-account-scope-tabs';
        tabs.setAttribute('aria-label', 'Account list scope');
        tabs.innerHTML = `
            <a href="#Account/list/primaryFilter=createdByMe" ${active ? 'aria-current="page"' : ''}>My Accounts</a>
            <a href="#Account" ${active ? '' : 'aria-current="page"'}>All Accounts</a>`;
        search.before(tabs);
    }

    async refreshRestoreRecordsButton() {
        if (!this.getUser().isAdmin() || this.restoreRecordsLoading) {
            this.deletedAccountTotal = 0;
            this.renderRestoreRecordsButton();
            return;
        }

        this.restoreRecordsLoading = true;
        try {
            const result = await Espo.Ajax.getRequest('Nexa/account/trash');
            this.deletedAccountTotal = Number(result.total) || 0;
        } catch (error) {
            this.deletedAccountTotal = 0;
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
        const existing = root.querySelector('.nexa-account-trash-button') ||
            root.parentElement?.querySelector('.nexa-account-trash-button');
        const createButton = headerButtons?.querySelector('[data-action="create"], [data-name="create"]');

        createButton?.classList.add('nexa-account-header-action');
        if (!headerButtons || !this.getUser().isAdmin() || !this.deletedAccountTotal) {
            existing?.remove();
            return;
        }
        if (existing) return;

        const button = document.createElement('a');
        button.className = 'btn btn-default nexa-account-header-action nexa-account-trash-button';
        button.href = '#Account/trash';
        button.innerHTML = '<span class="fas fa-undo-alt" aria-hidden="true"></span><span>Restore records</span>';
        button.setAttribute('aria-label', `Restore deleted account records (${this.deletedAccountTotal})`);
        button.title = `${this.deletedAccountTotal} deleted ${this.deletedAccountTotal === 1 ? 'record' : 'records'}`;
        createButton ? createButton.after(button) : headerButtons.appendChild(button);
    }
});
