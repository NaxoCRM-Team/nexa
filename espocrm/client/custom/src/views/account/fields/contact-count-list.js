define('custom:views/account/fields/contact-count-list', ['views/fields/int'], Dep => class extends Dep {
    listTemplate = 'custom:account/fields/contact-count-list';

    setup() {
        super.setup();
        this.listenTo(this.model, 'change:contactCount change:contactCountUnavailable', () => this.reRender());
    }

    data() {
        const rawCount = this.model.get('contactCount');
        const accountName = this.model.get('name') || 'this account';
        const count = Number.isFinite(Number(rawCount)) && rawCount !== null ? Number(rawCount) : 0;
        const query = new URLSearchParams({id: this.model.id, name: accountName});

        return {
            ...super.data(),
            accountName,
            count,
            isLoading: rawCount === null || typeof rawCount === 'undefined',
            isUnavailable: Boolean(this.model.get('contactCountUnavailable')),
            contactsUrl: `#Contact/account?${query.toString()}`,
        };
    }
});
