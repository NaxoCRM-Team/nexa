define('custom:handlers/create-related/document-from-contact', ['handlers/create-related'], Dep => {
    /**
     * Stock EspoCRM only auto-fills a related record's Account from the
     * Contact panel via a flat createAttributeMap (accountId -> accountId),
     * which works for Case/Opportunity's singular `account` link field but
     * not Document's `accounts` (linkMultiple - needs an array/id-name-map
     * shape, not a bare copied string). This handler does that shaping.
     */
    return class extends Dep {
        getAttributes(model) {
            const accountId = model.get('accountId');
            if (!accountId) return Promise.resolve({});

            const accountName = model.get('accountName');

            return Promise.resolve({
                accountsIds: [accountId],
                accountsNames: {[accountId]: accountName},
            });
        }
    };
});
