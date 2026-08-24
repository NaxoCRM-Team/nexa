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
            if (this.getUser().isAdmin()) {
                this.addMassAction({name: 'removeDoNotContact', groupIndex: 1}, false, true);
            }
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
        this.setupColumnResize();

        return result;
    }

    // Excel/HubSpot-style column resizing: a thin draggable handle on the
    // right edge of each header cell. Widths are stored per-user (keyed by
    // column name) so a resize sticks across visits, not just the session.
    // Called on every render AND after each infinite-scroll page loads, since
    // newly appended rows need the saved widths applied too - handle
    // creation itself is guarded separately so it only happens once.
    setupColumnResize() {
        const table = this.element?.querySelector('table.nexa-crm-table');
        if (!table) return;

        const storageKey = `nexaListColumnWidths:${this.entityType}`;
        const savedWidths = this.getStorage().get('state', storageKey) || {};

        if (table !== this.resizeTable) {
            // A brand new table (first render, or re-rendered by a sort/filter
            // change) starts out width: 100% and auto-shrinks everything to
            // fit - freeze it to explicit pixel widths first so it's capable
            // of growing past the container at all once saved widths are
            // reapplied below.
            this.freezeColumnWidths(table);
        }

        Object.entries(savedWidths).forEach(([columnName, width]) => {
            this.applyColumnWidth(table, columnName, width);
        });

        if (table === this.resizeTable) return;
        this.resizeTable = table;

        table.querySelectorAll('thead > tr > th[data-name]').forEach(th => {
            const columnName = th.dataset.name;
            if (columnName === 'r-checkbox' || th.classList.contains('action-cell')) return;

            const handle = document.createElement('span');
            handle.className = 'nexa-col-resizer';
            handle.setAttribute('aria-hidden', 'true');
            th.style.position = 'relative';
            th.append(handle);

            handle.addEventListener('mousedown', event => {
                event.preventDefault();
                event.stopPropagation();
                this.startColumnResize(table, th, columnName, event, storageKey, savedWidths);
            });
        });
    }

    startColumnResize(table, th, columnName, startEvent, storageKey, savedWidths) {
        const startX = startEvent.pageX;
        const startWidth = th.getBoundingClientRect().width;
        const handle = th.querySelector('.nexa-col-resizer');
        handle?.classList.add('is-resizing');
        document.body.classList.add('nexa-col-resizing');

        const onMouseMove = moveEvent => {
            const newWidth = Math.max(60, startWidth + (moveEvent.pageX - startX));
            this.applyColumnWidth(table, columnName, newWidth);
        };

        const onMouseUp = () => {
            document.removeEventListener('mousemove', onMouseMove);
            document.removeEventListener('mouseup', onMouseUp);
            handle?.classList.remove('is-resizing');
            document.body.classList.remove('nexa-col-resizing');

            const finalWidth = th.getBoundingClientRect().width;
            savedWidths[columnName] = Math.round(finalWidth);
            this.getStorage().set('state', storageKey, savedWidths);
        };

        document.addEventListener('mousemove', onMouseMove);
        document.addEventListener('mouseup', onMouseUp);
    }

    applyColumnWidth(table, columnName, width) {
        const px = `${Math.round(width)}px`;
        table.querySelectorAll(
            `thead > tr > th[data-name="${columnName}"], tbody > tr > td[data-name="${columnName}"]`
        ).forEach(cell => { cell.style.width = px; });

        this.recalculateTableWidth(table);
    }

    // table-layout: fixed combined with a CSS width of 100% makes the browser
    // proportionally shrink every OTHER column to compensate whenever one
    // column is widened, so the table can never actually grow past its
    // container - which is exactly what should happen once columns no
    // longer fit, so the existing horizontal scrollbar can do its job.
    // Freezing every column (including the checkbox/action ones) to its
    // current pixel width and driving the table's own width from their sum
    // breaks that compensation: the table becomes exactly as wide as its
    // columns need, growing beyond the container once they don't all fit.
    freezeColumnWidths(table) {
        table.querySelectorAll('thead > tr > th').forEach(th => {
            const width = Math.round(th.getBoundingClientRect().width);
            th.style.width = `${width}px`;

            const columnName = th.dataset.name;
            if (!columnName) return;

            table.querySelectorAll(`tbody > tr > td[data-name="${columnName}"]`).forEach(td => {
                td.style.width = `${width}px`;
            });
        });

        this.recalculateTableWidth(table);
    }

    recalculateTableWidth(table) {
        let total = 0;
        table.querySelectorAll('thead > tr > th').forEach(th => {
            total += th.getBoundingClientRect().width;
        });
        table.style.width = `${Math.round(total)}px`;
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
            this.setupColumnResize();
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
        if (!this.getUser().isAdmin()) {
            Espo.Ui.error('Only a tenant admin can remove a communication restriction.');
            return false;
        }
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
        const channels = status === 'allowed' ? this.activeCommunicationChannels(ids) : [];
        if (status === 'allowed' && !channels.length) {
            Espo.Ui.warning('The selected contacts have no active communication restrictions.');
            return false;
        }

        this.createView('contactCommunicationPreference', 'custom:views/contact/modals/communication-preference', {
            count: ids.length,
            status,
            channels,
        }, view => {
            view.render();
            this.listenToOnce(view, 'confirm', data => this.updateCommunicationPreference(ids, data));
        });
    }

    activeCommunicationChannels(ids) {
        const selected = new Set(ids);
        const channels = new Set();

        this.collection.models.forEach(model => {
            if (!selected.has(model.id)) return;
            String(model.get('doNotContactChannels') || '').split(',').filter(Boolean)
                .forEach(channel => channels.add(channel));
        });

        return [...channels];
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
