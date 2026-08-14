define('custom:views/contact/record/list-infinite-v2', [
    'views/record/list',
    'custom:table-inline-editor',
    'helpers/export',
], (Dep, TableInlineEditor, ExportHelper) => class extends Dep {
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

    setupMassActionItems() {
        super.setupMassActionItems();
        this.removeMassAction('massUpdate');

        if (
            this.getAcl().check(this.entityType, 'edit') &&
            this.getAcl().getPermissionLevel('massUpdatePermission') === 'yes'
        ) {
            this.addMassAction({name: 'assign', groupIndex: 0}, false, true);
            this.addMassAction({name: 'setDoNotContact', groupIndex: 1}, false, true);
            this.addMassAction({name: 'removeDoNotContact', groupIndex: 1}, false, true);
        }
    }

    getSelectAttributeList(callback) {
        super.getSelectAttributeList(attributeList => {
            if (attributeList && !attributeList.includes('profileImageId')) {
                attributeList.push('profileImageId');
            }
            ['doNotContact', 'doNotContactChannels'].forEach(attribute => {
                if (attributeList && !attributeList.includes(attribute)) attributeList.push(attribute);
            });

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

    massActionExport() {
        if (this.getConfig().get('exportDisabled') && !this.getUser().isAdmin()) return;

        this.exportContacts();
    }

    massActionAssign() {
        if (!this.getAcl().check(this.entityType, 'edit')) {
            Espo.Ui.error(this.translate('Access denied'));
            return false;
        }
        if (this.allResultIsChecked) {
            Espo.Ui.warning('Select up to 500 individual contacts before assigning an owner.');
            return false;
        }

        const ids = [...this.checkedList];
        if (!ids.length) return false;

        this.createView('contactBulkAssign', 'custom:views/contact/modals/bulk-assign', {
            count: ids.length,
        }, view => {
            view.render();
            this.listenToOnce(view, 'confirm', user => this.assignContacts(ids, user));
        });
    }

    async assignContacts(ids, user) {
        Espo.Ui.notify('Updating contact owners...');

        try {
            const result = await Espo.Ajax.postRequest('Nexa/contact/assign', {
                ids,
                assignedUserId: user?.id || null,
            });
            await this.collection.fetch();
            this.uncheckAll();
            Espo.Ui.success(`${result.count} ${result.count === 1 ? 'contact' : 'contacts'} assigned.`);
        } catch (error) {
            Espo.Ui.notify(false);
            Espo.Ui.error('The selected contacts could not be assigned.');
        }
    }

    massActionSetDoNotContact() {
        return this.openCommunicationPreference('blocked');
    }

    massActionRemoveDoNotContact() {
        return this.openCommunicationPreference('allowed');
    }

    openCommunicationPreference(status) {
        if (!this.getAcl().check(this.entityType, 'edit')) {
            Espo.Ui.error(this.translate('Access denied'));
            return false;
        }
        if (this.allResultIsChecked) {
            Espo.Ui.warning('Select up to 500 individual contacts before changing communication preferences.');
            return false;
        }

        const ids = [...this.checkedList];
        if (!ids.length) return false;

        this.createView('contactCommunicationPreference', 'custom:views/contact/modals/communication-preference', {
            count: ids.length,
            status,
        }, view => {
            view.render();
            this.listenToOnce(view, 'confirm', data => this.updateCommunicationPreference(ids, data));
        });
    }

    async updateCommunicationPreference(ids, data) {
        Espo.Ui.notify('Updating communication preferences...');

        try {
            const result = await Espo.Ajax.postRequest('Nexa/contact/communication-preference', {ids, ...data});
            await this.collection.fetch();
            this.uncheckAll();
            const action = result.status === 'blocked' ? 'restricted' : 'restored';
            Espo.Ui.success(`${result.count} ${result.count === 1 ? 'contact' : 'contacts'} ${action}.`);
        } catch (error) {
            Espo.Ui.notify(false);
            Espo.Ui.error(error?.message || 'The communication preference could not be updated.');
        }
    }

    exportContacts() {
        const data = {entityType: this.entityType};
        const exportsAllResults = this.allResultIsChecked;

        if (exportsAllResults) {
            data.where = this.collection.getWhere();
            data.searchParams = this.collection.data || null;
        } else {
            data.ids = [...this.checkedList];
        }

        const fieldList = (this.listLayout || []).map(item => item.name).filter(Boolean);
        const requestedCount = exportsAllResults ? Number(this.collection.total) : data.ids.length;
        const count = Number.isFinite(requestedCount) && requestedCount >= 0
            ? requestedCount
            : this.collection.length;
        const source = exportsAllResults ? 'Filtered contacts' : 'Selected contacts';
        const helper = new ExportHelper(this);
        const idle = exportsAllResults && helper.checkIsIdle(this.collection.total);

        this.createView('contactExport', 'custom:views/contact/modals/export', {
            scope: this.entityType,
            fieldList,
            count,
            source,
        }, view => {
            view.render();
            this.listenToOnce(view, 'proceed', dialogData => {
                if (!dialogData.exportAllFields) {
                    data.attributeList = dialogData.attributeList;
                    data.fieldList = dialogData.fieldList;
                }

                data.idle = idle;
                data.format = dialogData.format;
                data.params = dialogData.params;
                Espo.Ui.notify(this.translate('pleaseWait', 'messages'));

                Espo.Ajax.postRequest('Export', data, {timeout: 0})
                    .then(response => {
                        Espo.Ui.notify(false);

                        if (response.exportId) {
                            helper.process(response.exportId).then(idleView => {
                                this.listenToOnce(idleView, 'download', attachmentId => {
                                    this.completeContactExport(attachmentId, source, count, data.format, dialogData.exportName);
                                });
                            });
                            return;
                        }

                        if (!response.id) throw new Error('No export attachment was returned.');

                        return this.completeContactExport(response.id, source, count, data.format, dialogData.exportName);
                    })
                    .catch(() => {
                        Espo.Ui.notify(false);
                        Espo.Ui.error('The contact export could not be completed.');
                    });
            });
        });
    }

    async completeContactExport(attachmentId, source, count, format, exportName) {
        let audited = true;

        try {
            await Espo.Ajax.postRequest('Nexa/contact-export/audit', {
                attachmentId,
                source,
                count,
                format,
                exportName,
            });
        } catch (error) {
            // The generated file remains useful even when audit persistence is unavailable.
            audited = false;
        }

        try {
            await this.downloadContactExport(attachmentId);
        } catch (error) {
            Espo.Ui.error('The export file could not be downloaded.');
            return;
        }

        if (!audited) {
            Espo.Ui.warning('The file was downloaded, but its audit entry could not be recorded.');
            return;
        }

        Espo.Ui.success(`${count} ${count === 1 ? 'contact' : 'contacts'} exported.`);
        window.setTimeout(() => this.getRouter().navigate('#Contact/exportAudit', {trigger: true}), 250);
    }

    async downloadContactExport(attachmentId) {
        const result = await Espo.Ajax.getRequest(
            `Nexa/contact-export/${encodeURIComponent(attachmentId)}/download`
        );
        const binary = window.atob(result.contents || '');
        const bytes = new Uint8Array(binary.length);

        for (let index = 0; index < binary.length; index++) {
            bytes[index] = binary.charCodeAt(index);
        }

        const url = URL.createObjectURL(new Blob([bytes], {
            type: result.type || 'application/octet-stream',
        }));
        const link = document.createElement('a');
        link.href = url;
        link.download = result.name || 'contacts-export';
        link.hidden = true;
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(() => URL.revokeObjectURL(url), 1000);
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
            document.dispatchEvent(new CustomEvent('nexa:contact-trash-changed'));
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
