define('custom:views/account/record/list-infinite-v2', [
    'views/record/list',
    'custom:table-inline-editor',
    'helpers/export',
], (Dep, TableInlineEditor, ExportHelper) => class extends Dep {
    setup() {
        super.setup();
        this.inlineEditor = new TableInlineEditor(this, 'Account', {
            website: {type: 'text', inputType: 'url', maxLength: 255},
            phoneNumber: {type: 'text', inputType: 'tel', maxLength: 50},
            type: {type: 'dropdown'},
            industry: {type: 'dropdown'},
            annualRevenue: {
                type: 'text', inputType: 'number',
                normalize: value => value === '' ? null : Number(value),
            },
            numberOfEmployees: {
                type: 'text', inputType: 'number',
                normalize: value => value === '' ? null : Number.parseInt(value, 10),
            },
        });
        this.inlineEditor.setup();
        this.contactCountLoadedIds = new Set();
        this.contactCountPendingIds = new Set();

        this.listenTo(this.collection, 'sync reset', () => {
            this.scheduleScrollCheck();
            this.loadContactCounts();
        });
        this.listenTo(this.collection, 'error', () => { this.loadingNextPage = false; });
        this.once('remove', () => {
            this.scrollObserver?.disconnect();
            this.releaseScrollContainer();
            this.contactCountLoadedIds.clear();
            this.contactCountPendingIds.clear();
        });
    }

    getSelectAttributeList(callback) {
        super.getSelectAttributeList(attributeList => {
            ['website', 'companyLogoId'].forEach(attribute => {
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
        this.loadContactCounts();
        return result;
    }

    /** Load relationship totals in one request while the server applies CRM access control. */
    async loadContactCounts() {
        const models = this.collection.models.filter(model => model.id &&
            !this.contactCountLoadedIds.has(model.id) && !this.contactCountPendingIds.has(model.id));
        if (!models.length) return;

        const ids = models.map(model => model.id);
        ids.forEach(id => this.contactCountPendingIds.add(id));
        models.forEach(model => model.set({contactCount: null, contactCountUnavailable: false}));

        try {
            const result = await Espo.Ajax.postRequest('Nexa/account/contact-counts', {ids});
            const counts = result.counts || {};
            models.forEach(model => {
                model.set({
                    contactCount: Math.max(0, Number(counts[model.id]) || 0),
                    contactCountUnavailable: false,
                });
                this.contactCountLoadedIds.add(model.id);
            });
        } catch (error) {
            models.forEach(model => model.set({contactCount: null, contactCountUnavailable: true}));
        } finally {
            ids.forEach(id => this.contactCountPendingIds.delete(id));
        }
    }

    setupColumnResize() {
        const table = this.element?.querySelector('table.nexa-crm-table');
        if (!table) return;

        const storageKey = `nexaListColumnWidths:${this.entityType}`;
        const savedWidths = this.getStorage().get('state', storageKey) || {};
        if (table !== this.resizeTable) this.freezeColumnWidths(table);
        Object.entries(savedWidths).forEach(([name, width]) => this.applyColumnWidth(table, name, width));
        if (table === this.resizeTable) return;
        this.resizeTable = table;

        table.querySelectorAll('thead > tr > th[data-name]').forEach(th => {
            const name = th.dataset.name;
            if (name === 'r-checkbox' || th.classList.contains('action-cell')) return;

            const handle = document.createElement('span');
            handle.className = 'nexa-col-resizer';
            handle.setAttribute('aria-hidden', 'true');
            th.style.position = 'relative';
            th.append(handle);
            handle.addEventListener('mousedown', event => {
                event.preventDefault();
                event.stopPropagation();
                this.startColumnResize(table, th, name, event, storageKey, savedWidths);
            });
        });
    }

    startColumnResize(table, th, name, event, storageKey, widths) {
        const startX = event.pageX;
        const startWidth = th.getBoundingClientRect().width;
        const handle = th.querySelector('.nexa-col-resizer');
        handle?.classList.add('is-resizing');
        document.body.classList.add('nexa-col-resizing');

        const move = moveEvent => this.applyColumnWidth(table, name,
            Math.max(60, startWidth + moveEvent.pageX - startX));
        const finish = () => {
            document.removeEventListener('mousemove', move);
            document.removeEventListener('mouseup', finish);
            handle?.classList.remove('is-resizing');
            document.body.classList.remove('nexa-col-resizing');
            widths[name] = Math.round(th.getBoundingClientRect().width);
            this.getStorage().set('state', storageKey, widths);
        };

        document.addEventListener('mousemove', move);
        document.addEventListener('mouseup', finish);
    }

    applyColumnWidth(table, name, width) {
        const px = `${Math.round(width)}px`;
        table.querySelectorAll(`thead > tr > th[data-name="${name}"], tbody > tr > td[data-name="${name}"]`)
            .forEach(cell => { cell.style.width = px; });
        this.recalculateTableWidth(table);
    }

    freezeColumnWidths(table) {
        table.querySelectorAll('thead > tr > th').forEach(th => {
            const width = Math.round(th.getBoundingClientRect().width);
            th.style.width = `${width}px`;
            if (th.dataset.name) {
                table.querySelectorAll(`tbody > tr > td[data-name="${th.dataset.name}"]`)
                    .forEach(td => { td.style.width = `${width}px`; });
            }
        });
        this.recalculateTableWidth(table);
    }

    recalculateTableWidth(table) {
        let total = 0;
        table.querySelectorAll('thead > tr > th').forEach(th => { total += th.getBoundingClientRect().width; });
        table.style.width = `${Math.round(total)}px`;
    }

    bindScrollContainer() {
        const container = this.element?.matches('.list') ? this.element : this.element?.querySelector('.list');
        if (!container || container === this.scrollContainer) return;

        this.releaseScrollContainer();
        this.scrollContainer = container;
        container.classList.add('nexa-account-scroll-list');
        container.setAttribute('aria-description', 'More accounts load as you scroll.');
        this.scrollHandler = () => this.loadNextPageWhenNeeded();
        container.addEventListener('scroll', this.scrollHandler, {passive: true});
    }

    observeScrollContainer() {
        if (this.scrollObserver || !this.element) return;
        this.scrollObserver = new MutationObserver(() => {
            this.bindScrollContainer();
            this.inlineEditor.decorate();
            this.loadContactCounts();
            this.scheduleScrollCheck();
            this.setupColumnResize();
        });
        this.scrollObserver.observe(this.element, {childList: true, subtree: true});
    }

    releaseScrollContainer() {
        this.scrollContainer?.removeEventListener('scroll', this.scrollHandler);
        this.scrollContainer = null;
        this.scrollHandler = null;
    }

    scheduleScrollCheck() {
        window.requestAnimationFrame(() => this.loadNextPageWhenNeeded());
    }

    loadNextPageWhenNeeded() {
        const container = this.scrollContainer;
        if (!container || this.loadingNextPage || this.collection.isBeingFetched() || !this.collection.hasMore()) return;
        if (container.scrollHeight - container.scrollTop - container.clientHeight > 180) return;

        this.loadingNextPage = true;
        this.showMoreRecords({skipNotify: true}, null, null, null, () => {
            this.loadingNextPage = false;
            this.announceLoadedPage();
            this.scheduleScrollCheck();
        });
    }

    announceLoadedPage() {
        let status = this.element?.querySelector('.nexa-account-scroll-status');
        if (!status && this.element) {
            status = document.createElement('span');
            status.className = 'sr-only nexa-account-scroll-status';
            status.setAttribute('role', 'status');
            status.setAttribute('aria-live', 'polite');
            this.element.prepend(status);
        }
        if (status) status.textContent = `${this.collection.length} accounts loaded.`;
    }

    massActionRemove() {
        if (!this.getAcl().check(this.entityType, 'delete')) {
            Espo.Ui.error(this.translate('Access denied'));
            return false;
        }
        if (this.allResultIsChecked) {
            Espo.Ui.warning('Select up to 500 individual accounts before deleting.');
            return false;
        }

        return this.confirmAccountDeletion([...this.checkedList]);
    }

    massActionExport() {
        if (this.getConfig().get('exportDisabled') && !this.getUser().isAdmin()) return;

        this.exportAccounts();
    }

    exportAccounts() {
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
        const source = exportsAllResults ? 'Filtered accounts' : 'Selected accounts';
        const helper = new ExportHelper(this);
        const idle = exportsAllResults && helper.checkIsIdle(this.collection.total);

        this.createView('accountExport', 'custom:views/account/modals/export', {
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
                                    this.completeAccountExport(
                                        attachmentId, source, count, data.format, dialogData.exportName
                                    );
                                });
                            });
                            return;
                        }

                        if (!response.id) throw new Error('No export attachment was returned.');

                        return this.completeAccountExport(
                            response.id, source, count, data.format, dialogData.exportName
                        );
                    })
                    .catch(() => {
                        Espo.Ui.notify(false);
                        Espo.Ui.error('The account export could not be completed.');
                    });
            });
        });
    }

    async completeAccountExport(attachmentId, source, count, format, exportName) {
        try {
            await Espo.Ajax.postRequest('Nexa/contact-export/audit', {
                attachmentId,
                source,
                count,
                format,
                exportName,
            });
            await this.downloadAccountExport(attachmentId);
            Espo.Ui.success(`${count} ${count === 1 ? 'account' : 'accounts'} exported.`);
        } catch (error) {
            Espo.Ui.error('The account export could not be downloaded.');
        }
    }

    async downloadAccountExport(attachmentId) {
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
        link.download = result.name || 'accounts-export';
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

        return this.confirmAccountDeletion([model.id]);
    }

    confirmAccountDeletion(ids) {
        if (!ids.length) return false;

        this.createView('accountDeleteConfirmation', 'custom:views/account/modals/delete-confirmation', {
            count: ids.length,
        }, view => {
            view.render();
            this.listenToOnce(view, 'confirm', () => this.deleteAccounts(ids));
        });
    }

    async deleteAccounts(ids) {
        Espo.Ui.notifyWait();
        try {
            const result = await Espo.Ajax.postRequest('Nexa/account/delete', {ids});
            document.dispatchEvent(new CustomEvent('nexa:account-trash-changed'));
            (result.ids || []).forEach(id => {
                this.collection.trigger('model-removing', id);
                this.removeRecordFromList(id);
                this.uncheckRecord(id, null, true);
            });
            this.collection.trigger('after:mass-remove');
            Espo.Ui.success(`${result.count} ${result.count === 1 ? 'account' : 'accounts'} deleted.`);
        } finally {
            Espo.Ui.notify(false);
        }
    }
});
