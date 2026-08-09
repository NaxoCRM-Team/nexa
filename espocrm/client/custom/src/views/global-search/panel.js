define('custom:views/global-search/panel', ['views/global-search/panel'], BaseView => class extends BaseView {
    template = 'custom:global-search/panel';

    setup() {
        super.setup();
        this.query = this.options.query || '';
        this.addHandler('click', '[data-action="retrySearch"]', () => this.loadResults());
    }

    data() {
        return {query: this.query};
    }

    afterRender() {
        const $window = $(window);
        $window.off('resize.global-search-height');
        $window.on('resize.global-search-height', this.processSizing.bind(this));
        this.processSizing();
        this.loadResults();
    }

    setState(state, message) {
        const status = this.element.querySelector('.nexa-search-result-status');
        const list = this.element.querySelector('.list-container');
        const retry = this.element.querySelector('[data-action="retrySearch"]');

        this.element.dataset.state = state;
        status.textContent = message;
        list.hidden = state !== 'ready';
        retry.hidden = state !== 'error';
    }

    loadResults() {
        this.collection.reset();
        this.collection.maxSize = this.maxSize;
        this.setState('loading', `Searching this workspace for “${this.query}”…`);

        this.collection.fetch()
            .then(() => {
                if (!this.collection.length) {
                    this.setState('empty', `No accessible records match “${this.query}”.`);
                    return;
                }
                return this.createRecordView().then(view => {
                    this.setState('ready', `${this.collection.length} accessible result${this.collection.length === 1 ? '' : 's'} found.`);
                    return view.render();
                });
            })
            .catch(error => {
                const denied = error?.status === 401 || error?.status === 403;
                this.setState(denied ? 'denied' : 'error', denied ?
                    'Your role cannot search records in this workspace.' :
                    'Search could not be completed. Check the connection and try again.');
            });
    }
});
