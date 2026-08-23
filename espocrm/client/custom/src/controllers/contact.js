define('custom:controllers/contact', ['controllers/record'], Dep => {
    return class extends Dep {
        actionImport() {
            this.main('custom:views/contact/import', {}, view => view.render());
        }

        actionExportAudit() {
            this.main('custom:views/contact/export-audit', {}, view => view.render());
        }

        actionTrash() {
            if (!this.getUser().isAdmin()) {
                Espo.Ui.error('Only a tenant administrator can restore deleted records.');
                this.getRouter().navigate('#Contact', {trigger: true});
                return;
            }

            this.main('custom:views/contact/trash', {}, view => view.render());
        }

        /** Open the standard Contact workspace against one Account relationship endpoint. */
        actionAccount(options = {}) {
            const accountId = String(options.id || '').trim();
            const accountName = String(options.name || '').trim();
            if (!/^[a-zA-Z0-9_-]{1,64}$/.test(accountId)) {
                Espo.Ui.error('The selected account could not be opened.');
                this.getRouter().navigate('#Account', {trigger: true});
                return;
            }

            this.getCollection().then(collection => {
                const mediator = {};
                collection.url = `Account/${encodeURIComponent(accountId)}/contacts`;
                collection.offset = 0;

                const abort = () => {
                    collection.abortLastFetch();
                    mediator.abort = true;
                    Espo.Ui.notify(false);
                };
                this.listenToOnce(this.baseController, 'action', abort);
                this.listenToOnce(collection, 'sync', () => this.stopListening(this.baseController, 'action', abort));

                this.main(this.getViewName('list'), {
                    scope: this.name,
                    collection,
                    mediator,
                    params: {...options, accountId, accountName},
                }, null, {useStored: false, key: `listAccount${accountId}`});
            });
        }
    };
});
