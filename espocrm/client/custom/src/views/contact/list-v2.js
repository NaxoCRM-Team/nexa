define('custom:views/contact/list-v2', ['views/list'], Dep => class extends Dep {
    searchView = 'custom:views/contact/record/search-live-v2';
    recordView = 'custom:views/contact/record/list-infinite-v2';

    setup() {
        super.setup();
        this.once('remove', () => {
            this.contactControlsObserver?.disconnect();

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
        this.contactListElement = this.element;
        this.contactListElement?.classList.add('nexa-contact-list-page');
        this.decorateContactControls();
        this.observeContactControls();

        return result;
    }

    observeContactControls() {
        if (!this.element) return;

        this.contactControlsObserver?.disconnect();
        this.contactControlsObserver = new MutationObserver(() => this.decorateContactControls());
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

        if (settingsContainer && this.getUser().isAdmin() && !root.querySelector('.nexa-contact-trash-button')) {
            const trashButton = document.createElement('button');
            trashButton.type = 'button';
            trashButton.className = 'btn btn-default nexa-contact-trash-button';
            trashButton.innerHTML = '<span class="far fa-trash-alt" aria-hidden="true"></span><span>Deleted</span>';
            trashButton.setAttribute('aria-label', 'Open deleted contacts');
            trashButton.title = 'Deleted contacts';
            trashButton.addEventListener('click', () => this.openContactTrash());
            root.querySelector('.nexa-contact-import-button')?.after(trashButton);
        }

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

    async openContactTrash() {
        Espo.Ui.notifyWait();
        try {
            const result = await Espo.Ajax.getRequest('Nexa/contact/trash');
            this.createView('contactTrash', 'custom:views/contact/modals/trash', {
                records: result.list || [],
            }, view => {
                view.render();
                this.listenToOnce(view, 'restored', () => this.collection.fetch());
            });
        } finally {
            Espo.Ui.notify(false);
        }
    }
});
