define('custom:views/contact/list-v2', ['views/list'], Dep => class extends Dep {
    searchView = 'custom:views/contact/record/search-live-v2';
    recordView = 'custom:views/contact/record/list-infinite-v2';

    setup() {
        super.setup();
        this.once('remove', () => this.contactControlsObserver?.disconnect());
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
        this.element?.classList.add('nexa-contact-list-page');
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
});
