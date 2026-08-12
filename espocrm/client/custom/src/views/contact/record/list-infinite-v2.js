define('custom:views/contact/record/list-infinite-v2', ['views/record/list', 'custom:table-inline-editor'], (Dep, TableInlineEditor) => class extends Dep {
    setup() {
        super.setup();
        this.inlineEditor = new TableInlineEditor(this, 'Contact', {
            emailAddress: {type: 'text', inputType: 'email', maxLength: 255},
            title: {
                type: 'text',
                maxLength: 100,
                save: (model, value) => Espo.Ajax.postRequest(
                    `Nexa/contact/${encodeURIComponent(model.id)}/title`,
                    {value}
                ),
            },
            phoneNumber: {type: 'text', inputType: 'tel', maxLength: 50},
            leadStatus: {type: 'dropdown'},
        });
        this.inlineEditor.setup();

        this.listenTo(this.collection, 'sync reset', () => this.scheduleScrollCheck());
        this.listenTo(this.collection, 'error', () => {
            this.loadingNextPage = false;
        });
        this.once('remove', () => {
            this.scrollObserver?.disconnect();
            this.releaseScrollContainer();
        });
    }

    getSelectAttributeList(callback) {
        super.getSelectAttributeList(attributeList => {
            if (attributeList && !attributeList.includes('profileImageId')) {
                attributeList.push('profileImageId');
            }

            callback(attributeList);
        });
    }

    afterRender() {
        const result = super.afterRender();

        this.bindScrollContainer();
        this.inlineEditor.decorate();
        this.observeScrollContainer();
        this.scheduleScrollCheck();

        return result;
    }

    bindScrollContainer() {
        const container = this.element?.matches('.list') ? this.element : this.element?.querySelector('.list');
        if (!container || container === this.scrollContainer) return;

        this.releaseScrollContainer();
        this.scrollContainer = container;
        this.scrollContainer.classList.add('nexa-contact-scroll-list');
        this.scrollContainer.setAttribute('aria-description', 'More contacts load as you scroll.');
        this.scrollHandler = () => this.loadNextPageWhenNeeded();
        this.scrollContainer.addEventListener('scroll', this.scrollHandler, {passive: true});
    }

    observeScrollContainer() {
        if (this.scrollObserver || !this.element) return;

        // The first collection fetch can replace the empty-state markup after
        // the view renders. Observe that transition and attach to the final list.
        this.scrollObserver = new MutationObserver(() => {
            this.bindScrollContainer();
            this.inlineEditor.decorate();
            this.scheduleScrollCheck();
        });
        this.scrollObserver.observe(this.element, {childList: true, subtree: true});
    }

    releaseScrollContainer() {
        if (this.scrollContainer && this.scrollHandler) {
            this.scrollContainer.removeEventListener('scroll', this.scrollHandler);
        }

        this.scrollContainer = null;
        this.scrollHandler = null;
    }

    scheduleScrollCheck() {
        window.requestAnimationFrame(() => this.loadNextPageWhenNeeded());
    }

    loadNextPageWhenNeeded() {
        const container = this.scrollContainer;
        if (!container || this.loadingNextPage || this.collection.isBeingFetched() || !this.collection.hasMore()) return;

        const distanceToBottom = container.scrollHeight - container.scrollTop - container.clientHeight;
        if (distanceToBottom > 180) return;

        this.loadingNextPage = true;

        // The native incremental loader appends one bounded page and therefore
        // preserves sorting, active filters and the collection's scoped query.
        this.showMoreRecords({skipNotify: true}, null, null, null, () => {
            this.loadingNextPage = false;
            this.announceLoadedPage();
            this.scheduleScrollCheck();
        });
    }

    announceLoadedPage() {
        let status = this.element?.querySelector('.nexa-contact-scroll-status');

        if (!status && this.element) {
            status = document.createElement('span');
            status.className = 'sr-only nexa-contact-scroll-status';
            status.setAttribute('role', 'status');
            status.setAttribute('aria-live', 'polite');
            this.element.prepend(status);
        }

        if (status) {
            status.textContent = `${this.collection.length} contacts loaded.`;
        }
    }

    massActionRemove() {
        if (!this.getAcl().check(this.entityType, 'delete')) {
            Espo.Ui.error(this.translate('Access denied'));
            return false;
        }
        if (this.allResultIsChecked) {
            Espo.Ui.warning('Select up to 500 individual contacts before deleting.');
            return false;
        }

        return this.confirmContactDeletion([...this.checkedList]);
    }

    async actionQuickRemove(data = {}) {
        const model = data.id ? this.collection.get(data.id) : null;
        if (!model || !this.getAcl().checkModel(model, 'delete')) {
            Espo.Ui.error(this.translate('Access denied'));
            return;
        }

        return this.confirmContactDeletion([model.id]);
    }

    confirmContactDeletion(ids) {
        if (!ids.length) return false;

        this.createView('contactDeleteConfirmation', 'custom:views/contact/modals/delete-confirmation', {
            count: ids.length,
        }, view => {
            view.render();
            this.listenToOnce(view, 'confirm', () => this.deleteContacts(ids));
        });
    }

    async deleteContacts(ids) {
        Espo.Ui.notifyWait();
        try {
            const result = await Espo.Ajax.postRequest('Nexa/contact/delete', {ids});
            (result.ids || []).forEach(id => {
                this.collection.trigger('model-removing', id);
                this.removeRecordFromList(id);
                this.uncheckRecord(id, null, true);
            });
            this.collection.trigger('after:mass-remove');
            Espo.Ui.success(`${result.count} ${result.count === 1 ? 'contact' : 'contacts'} deleted.`);
        } finally {
            Espo.Ui.notify(false);
        }
    }
});
