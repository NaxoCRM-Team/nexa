define('custom:views/global-search/global-search', ['views/global-search/global-search'], BaseView => class extends BaseView {
    template = 'custom:global-search/global-search';

    setup() {
        super.setup();
        this.activeSuggestionIndex = -1;
        this.suggestionList = [];
        this.addHandler('input', 'input.global-search-input', 'onInput');
        this.addHandler('click', '[data-suggestion-index]', 'onSuggestionClick');
        this.addHandler('click', '[data-action="clearRecentSearches"]', 'clearRecentSearches');
    }

    afterRender() {
        this.$input = this.$el.find('input.global-search-input');
        this.inputElement = this.$input.get(0);
        this.inputElement?.setAttribute('aria-expanded', 'false');
        this.shortcutHandler = event => {
            const target = event.target;
            const isEditable = target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement ||
                target instanceof HTMLSelectElement || target?.isContentEditable;

            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                this.inputElement?.focus();
                return;
            }

            if (!isEditable && event.key === '/') {
                event.preventDefault();
                this.inputElement?.focus();
            }
        };
        document.addEventListener('keydown', this.shortcutHandler);
        this.once('remove', () => document.removeEventListener('keydown', this.shortcutHandler));
    }

    onFocus() {
        this.renderSuggestions(this.inputElement.value.trim());
    }

    onInput(event) {
        this.renderSuggestions(event.target.value.trim());
    }

    onKeydown(event) {
        const key = Espo.Utils.getKeyFromKeyEvent(event);

        if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key) && this.suggestionList.length) {
            event.preventDefault();
            const last = this.suggestionList.length - 1;
            if (event.key === 'ArrowDown') this.activeSuggestionIndex = Math.min(last, this.activeSuggestionIndex + 1);
            if (event.key === 'ArrowUp') this.activeSuggestionIndex = Math.max(0, this.activeSuggestionIndex - 1);
            if (event.key === 'Home') this.activeSuggestionIndex = 0;
            if (event.key === 'End') this.activeSuggestionIndex = last;
            this.syncActiveSuggestion();
            return;
        }

        if (event.key === 'Enter' || key === 'Enter' || key === 'Control+Enter') {
            event.preventDefault();
            if (this.activeSuggestionIndex >= 0) {
                this.activateSuggestion(this.suggestionList[this.activeSuggestionIndex]);
            } else {
                this.runSearch();
            }
            return;
        }

        if (key === 'Escape') {
            this.closeSuggestions();
            this.closePanel();
        }
    }

    getRecentStorageKey() {
        const tenant = this.getHelper().getAppParam('nexaTenant');
        const userId = this.getUser().id || this.getUser().get('id') || 'anonymous';

        return tenant?.id ? `nexaGlobalSearchRecent:${tenant.id}:${userId}` : null;
    }

    getRecentSearches() {
        const key = this.getRecentStorageKey();
        if (!key) return [];

        try {
            const value = JSON.parse(window.localStorage.getItem(key) || '[]');
            return Array.isArray(value) ? value.filter(item => typeof item === 'string').slice(0, 6) : [];
        } catch (error) {
            return [];
        }
    }

    saveRecentSearch(text) {
        const key = this.getRecentStorageKey();
        if (!key) return;

        const values = [text, ...this.getRecentSearches().filter(item => item.toLowerCase() !== text.toLowerCase())]
            .slice(0, 6);

        try {
            window.localStorage.setItem(key, JSON.stringify(values));
        } catch (error) {
            // Search remains usable when browser storage is unavailable.
        }
    }

    clearRecentSearches(event) {
        event?.preventDefault();
        const key = this.getRecentStorageKey();
        if (key) window.localStorage.removeItem(key);
        this.renderSuggestions(this.inputElement.value.trim());
        this.inputElement.focus();
    }

    getSuggestions(query) {
        const lower = query.toLowerCase();
        const recent = this.getRecentSearches()
            .filter(value => !query || value.toLowerCase().includes(lower))
            .map(value => ({type: 'recent', label: value, description: 'Recent search'}));
        const modules = query ? this.tabDataList
            .filter(item => item.lowerLabel.includes(lower) || item.words.some(word => word.startsWith(lower)))
            .slice(0, 5)
            .map(item => ({type: 'module', label: item.label, description: 'Open module', url: item.url})) : [];
        const search = query.length >= 2 ? [{
            type: 'search',
            label: query,
            description: `Search this workspace for “${query}”`,
        }] : [];

        return [...search, ...modules, ...recent].slice(0, 8);
    }

    renderSuggestions(query) {
        const list = this.element.querySelector('.nexa-search-suggestions');
        const heading = this.element.querySelector('.nexa-search-suggestions-heading');
        const clear = this.element.querySelector('[data-action="clearRecentSearches"]');
        if (!list) return;

        this.suggestionList = this.getSuggestions(query);
        this.activeSuggestionIndex = -1;
        list.replaceChildren();
        heading.textContent = query ? 'Suggestions' : 'Recent searches';
        clear.hidden = Boolean(query) || this.getRecentSearches().length === 0;

        this.suggestionList.forEach((suggestion, index) => {
            const option = document.createElement('button');
            const icon = document.createElement('span');
            const copy = document.createElement('span');
            const label = document.createElement('strong');
            const description = document.createElement('small');

            option.type = 'button';
            option.className = 'nexa-search-suggestion';
            option.id = `nexa-search-option-${index}`;
            option.dataset.suggestionIndex = String(index);
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');
            icon.className = suggestion.type === 'module' ? 'fas fa-arrow-right nexa-search-suggestion-icon' :
                suggestion.type === 'recent' ? 'fas fa-history nexa-search-suggestion-icon' :
                    'fas fa-search nexa-search-suggestion-icon';
            icon.setAttribute('aria-hidden', 'true');
            copy.className = 'nexa-search-suggestion-copy';
            label.textContent = suggestion.label;
            description.textContent = suggestion.description;
            copy.append(label, description);
            option.append(icon, copy);
            list.append(option);
        });

        const wrapper = this.element.querySelector('.nexa-search-suggestion-panel');
        const isOpen = this.suggestionList.length > 0 || !query;
        wrapper.hidden = !isOpen;
        this.inputElement.setAttribute('aria-expanded', String(isOpen));
    }

    syncActiveSuggestion() {
        this.element.querySelectorAll('.nexa-search-suggestion').forEach((option, index) => {
            const active = index === this.activeSuggestionIndex;
            option.setAttribute('aria-selected', String(active));
            option.classList.toggle('is-active', active);
            if (active) option.scrollIntoView({block: 'nearest'});
        });
        this.inputElement.setAttribute('aria-activedescendant', `nexa-search-option-${this.activeSuggestionIndex}`);
    }

    onSuggestionClick(event) {
        const index = Number(event.currentTarget.dataset.suggestionIndex);
        this.activateSuggestion(this.suggestionList[index]);
    }

    activateSuggestion(suggestion) {
        if (!suggestion) return;
        if (suggestion.type === 'module') {
            this.closeSuggestions();
            window.location.href = suggestion.url;
            return;
        }
        this.inputElement.value = suggestion.label;
        this.runSearch();
    }

    runSearch() {
        const text = this.inputElement?.value.trim() || '';
        if (!this.hasGlobalSearch || text.length < 2) {
            this.inputElement?.setAttribute('aria-invalid', text.length > 0 ? 'true' : 'false');
            return;
        }
        this.inputElement.setAttribute('aria-invalid', 'false');
        this.saveRecentSearch(text);
        this.closeSuggestions();
        this.search(text);
    }

    closeSuggestions() {
        const wrapper = this.element.querySelector('.nexa-search-suggestion-panel');
        if (wrapper) wrapper.hidden = true;
        this.inputElement?.setAttribute('aria-expanded', 'false');
        this.inputElement?.removeAttribute('aria-activedescendant');
        this.activeSuggestionIndex = -1;
    }

    showPanel() {
        this.closePanel();
        this.closeSuggestions();
        if (this.closeNavbarOnShow) this.$el.closest('.navbar-body').removeClass('in');

        const $container = this.$container = $('<div>').attr('id', 'global-search-panel');
        this.containerElement = $container.get(0);
        $container.appendTo(this.$el.find('.global-search-panel-container'));
        this.createView('panel', 'custom:views/global-search/panel', {
            fullSelector: '#global-search-panel',
            collection: this.collection,
            query: this.inputElement.value.trim(),
        }, view => {
            view.render();
            this.listenToOnce(view, 'close', this.closePanel);
        });
        document.addEventListener('mouseup', this.onMouseUpBind);
        document.addEventListener('click', this.onClickBind);
    }
});
