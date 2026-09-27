define('custom:views/asset/workspace', ['view', 'custom:workspace-table'], (Dep, WorkspaceTable) => class extends Dep {
    template = 'custom:asset/workspace';
    events = {
        'click [data-action="reload"]': 'load', 'click [data-action="new-asset"]': 'newAsset',
        'click [data-action="close-asset"]': 'closeEditor', 'submit [data-asset-form]': 'saveAsset',
        'input [data-asset-search]': 'queueSearch', 'change [data-asset-type]': 'reload', 'change [data-asset-status]': 'reload',
        'click [data-table-sort]': 'sort', 'click [data-action="edit-asset"]': 'editAsset',
        'click [data-action="download-asset"]': 'downloadAsset', 'click [data-action="toggle-archive"]': 'toggleArchive',
        'click [data-action="close-asset-confirm"]': 'closeStatusConfirm', 'click [data-action="confirm-asset-status"]': 'confirmStatusChange',
    };

    setup() {
        this.setPageTitle('Content & Assets'); this.items = []; this.total = 0; this.offset = 0; this.limit = 50;
        this.orderBy = 'modifiedAt'; this.direction = 'desc'; this.loadingMore = false; this.current = null;
        this.tableManager = new WorkspaceTable(this);
    }

    afterRender() {
        super.afterRender(); document.body.classList.add('nexa-assets-page');
        this.element.querySelectorAll('.nexa-asset-overlay').forEach(dialog => this.setDialogOpen(dialog, false));
        this.once('remove', () => { document.body.classList.remove('nexa-assets-page'); clearTimeout(this.searchTimer); this.scroll?.removeEventListener('scroll', this.scrollHandler); });
        this.load();
    }

    async load() {
        if (!this.element) return;
        this.setState('loading'); this.offset = 0;
        try {
            const response = await this.fetchPage(0); this.items = response.list || []; this.total = Number(response.total || 0);
            this.folders = response.folders || this.folders || []; this.permissions = response.permissions || this.permissions || {};
            this.renderRows(); this.populateFolders(); this.setState('ready'); this.bindScroll();
        } catch (error) { this.setState('error'); Espo.Ui.error(error?.message || 'Assets could not be loaded.'); }
    }

    fetchPage(offset) {
        return Espo.Ajax.getRequest('Nexa/assets/workspace', {search: this.element.querySelector('[data-asset-search]')?.value.trim() || '', type: this.element.querySelector('[data-asset-type]')?.value || 'all', status: this.element.querySelector('[data-asset-status]')?.value || 'active', offset, limit: this.limit, orderBy: this.orderBy, direction: this.direction});
    }

    setState(state) { this.element.querySelectorAll('[data-asset-state]').forEach(node => { node.hidden = node.dataset.assetState !== state; node.style.display = node.hidden ? 'none' : ''; }); }

    renderRows() {
        const body = this.element.querySelector('[data-asset-list]');
        body.innerHTML = this.items.map(item => `<tr>
            <td data-column="name"><span class="nexa-asset-name"><span class="nexa-asset-file-icon fas ${this.icon(item)}"></span><span><strong>${this.escape(item.name)}</strong><small>${this.escape(item.fileName)}</small></span></span></td>
            <td data-column="type">${this.escape(this.typeLabel(item))}</td><td data-column="folder">${this.escape(item.folderName || 'No folder')}</td>
            <td data-column="access"><span class="nexa-asset-badge is-${this.escape(item.accessScope)}">${this.escape(this.title(item.accessScope))}</span></td>
            <td data-column="version">v${Number(item.version || 1)}</td><td data-column="downloads">${Number(item.downloads || 0)}</td>
            <td data-column="modifiedAt"><span>${this.date(item.modifiedAt, true)}</span><small>${this.escape(item.modifiedByName || '')}</small></td>
            <td data-column="actions"><div class="nexa-asset-actions"><button class="btn btn-icon" type="button" data-action="download-asset" data-id="${this.escape(item.id)}" title="Download"><span class="fas fa-download"></span></button>${this.permissions?.edit ? `<button class="btn btn-icon" type="button" data-action="edit-asset" data-id="${this.escape(item.id)}" title="Edit"><span class="fas fa-pen"></span></button>` : ''}${this.permissions?.delete ? `<button class="btn btn-icon" type="button" data-action="toggle-archive" data-id="${this.escape(item.id)}" title="${item.status === 'archived' ? 'Restore' : 'Archive'}"><span class="fas fa-${item.status === 'archived' ? 'undo' : 'archive'}"></span></button>` : ''}</div></td>
        </tr>`).join('') || '<tr><td colspan="8" class="nexa-asset-empty"><span class="far fa-folder-open"></span><strong>No assets found</strong><span>Upload a file or adjust the current filters.</span></td></tr>';
        this.element.querySelector('[data-asset-total]').textContent = `${this.total} ${this.total === 1 ? 'asset' : 'assets'}`;
        this.element.querySelector('[data-asset-loaded]').textContent = this.total ? `Showing ${this.items.length} of ${this.total}` : '';
        this.tableManager.enhance(this.element.querySelector('[data-workspace-table="assets"]'), 'assets');
    }

    queueSearch() { clearTimeout(this.searchTimer); this.searchTimer = setTimeout(() => this.load(), 220); }
    sort(event) { const key = event.currentTarget.dataset.tableSort; this.direction = this.orderBy === key && this.direction === 'asc' ? 'desc' : 'asc'; this.orderBy = key; this.load(); }
    bindScroll() { this.scroll = this.element.querySelector('[data-asset-scroll]'); this.scrollHandler ||= () => { if (this.scroll.scrollTop + this.scroll.clientHeight >= this.scroll.scrollHeight - 100) this.loadMore(); }; this.scroll.removeEventListener('scroll', this.scrollHandler); this.scroll.addEventListener('scroll', this.scrollHandler); }
    async loadMore() { if (this.loadingMore || this.items.length >= this.total) return; this.loadingMore = true; try { const response = await this.fetchPage(this.items.length); this.items.push(...(response.list || [])); this.renderRows(); } catch (error) { Espo.Ui.error('More assets could not be loaded.'); } finally { this.loadingMore = false; } }

    newAsset() { if (!this.permissions?.create) return Espo.Ui.error('You do not have permission to upload assets.'); this.openEditor(null); }
    editAsset(event) { const item = this.items.find(row => row.id === event.currentTarget.dataset.id); if (item) this.openEditor(item); }
    openEditor(item) {
        this.current = item; const dialog = this.element.querySelector('[data-asset-dialog]'); const form = dialog.querySelector('[data-asset-form]'); form.reset();
        dialog.querySelector('[data-asset-dialog-title]').textContent = item ? 'Edit asset' : 'Upload asset';
        dialog.querySelector('[data-asset-file-field]').hidden = !!item; dialog.querySelector('[data-asset-replacement]').hidden = !item;
        form.elements.file.required = !item; form.elements.displayName.value = item?.name || ''; form.elements.description.value = item?.description || '';
        form.elements.altText.value = item?.altText || ''; form.elements.locale.value = item?.locale || 'en'; form.elements.accessScope.value = item?.accessScope || 'internal';
        form.elements.folderId.value = item?.folderId || ''; form.elements.tags.value = (item?.tags || []).join(', ');
        dialog.querySelector('[data-asset-form-error]').hidden = true; this.setDialogOpen(dialog, true); form.elements[item ? 'displayName' : 'file'].focus();
    }
    closeEditor() { this.setDialogOpen(this.element.querySelector('[data-asset-dialog]'), false); this.current = null; }
    populateFolders() { const select = this.element.querySelector('[data-asset-folders]'); if (!select) return; select.innerHTML = '<option value="">No folder</option>' + (this.folders || []).map(folder => `<option value="${this.escape(folder.id)}">${this.escape(folder.name)}</option>`).join(''); }

    async saveAsset(event) {
        event.preventDefault(); const form = event.currentTarget; const file = this.current ? form.elements.replacement.files?.[0] : form.elements.file.files?.[0];
        const isEditing = !!this.current;
        if (!this.current && !file) return this.formError('Choose a file to upload.');
        if (file && !this.validSize(file)) return;
        const payload = {displayName: form.elements.displayName.value.trim() || file?.name || '', description: form.elements.description.value.trim(), altText: form.elements.altText.value.trim(), locale: form.elements.locale.value.trim() || 'en', accessScope: form.elements.accessScope.value, folderId: form.elements.folderId.value || null, tags: form.elements.tags.value.split(',').map(value => value.trim()).filter(Boolean)};
        Espo.Ui.notifyWait();
        try {
            if (this.current) {
                await Espo.Ajax.putRequest(`Nexa/assets/${encodeURIComponent(this.current.id)}`, payload);
                if (file) await Espo.Ajax.postRequest(`Nexa/assets/${encodeURIComponent(this.current.id)}/version`, {...await this.filePayload(file)});
            } else await Espo.Ajax.postRequest('Nexa/assets', {...payload, ...await this.filePayload(file)});
            this.closeEditor(); await this.load(); Espo.Ui.success(isEditing ? 'Asset updated' : 'Asset uploaded');
        } catch (error) { this.formError(error?.message || 'The asset could not be saved.'); } finally { Espo.Ui.notify(false); }
    }

    validSize(file) { const max = file.type.startsWith('image/') ? 8 * 1024 * 1024 : 25 * 1024 * 1024; if (file.size <= max) return true; this.formError(file.type.startsWith('image/') ? 'Images cannot be larger than 8 MB.' : 'Files cannot be larger than 25 MB.'); return false; }
    filePayload(file) { return new Promise((resolve, reject) => { const reader = new FileReader(); reader.onload = () => resolve({fileName: file.name, mimeType: file.type || 'application/octet-stream', data: String(reader.result || '')}); reader.onerror = reject; reader.readAsDataURL(file); }); }
    formError(message) { const node = this.element.querySelector('[data-asset-form-error]'); node.textContent = message; node.hidden = false; }

    async downloadAsset(event) { try { Espo.Ui.notifyWait(); const item = await Espo.Ajax.getRequest(`Nexa/assets/${encodeURIComponent(event.currentTarget.dataset.id)}/download`); const bytes = Uint8Array.from(atob(item.data), char => char.charCodeAt(0)); const url = URL.createObjectURL(new Blob([bytes], {type: item.mimeType || 'application/octet-stream'})); const link = document.createElement('a'); link.href = url; link.download = item.name || 'asset'; link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000); } catch (error) { Espo.Ui.error(error?.message || 'The asset could not be downloaded.'); } finally { Espo.Ui.notify(false); } }
    toggleArchive(event) {
        const item = this.items.find(row => row.id === event.currentTarget.dataset.id); if (!item) return;
        const action = item.status === 'archived' ? 'restore' : 'archive'; this.pendingStatus = {item, action};
        const dialog = this.element.querySelector('[data-asset-confirm-dialog]');
        dialog.querySelector('[data-asset-confirm-title]').textContent = `${this.title(action)} asset?`;
        dialog.querySelector('[data-asset-confirm-copy]').textContent = `${this.title(action)} “${item.name}”?`;
        const button = dialog.querySelector('[data-action="confirm-asset-status"]'); button.textContent = `${this.title(action)} asset`; button.classList.toggle('btn-danger', action === 'archive'); button.classList.toggle('btn-primary', action === 'restore');
        this.setDialogOpen(dialog, true); button.focus();
    }
    closeStatusConfirm() { this.setDialogOpen(this.element.querySelector('[data-asset-confirm-dialog]'), false); this.pendingStatus = null; }
    async confirmStatusChange() {
        if (!this.pendingStatus) return; const {item, action} = this.pendingStatus;
        try { await Espo.Ajax.postRequest(`Nexa/assets/${encodeURIComponent(item.id)}/${action}`); this.closeStatusConfirm(); await this.load(); Espo.Ui.success(`Asset ${action === 'archive' ? 'archived' : 'restored'}`); }
        catch (error) { Espo.Ui.error(error?.message || `The asset could not be ${action}d.`); }
    }
    setDialogOpen(dialog, open) {
        if (!dialog) return;
        dialog.hidden = !open; dialog.setAttribute('aria-hidden', open ? 'false' : 'true');
        dialog.style.display = open ? 'flex' : 'none';
        if (open) {
            dialog.style.position = 'fixed'; dialog.style.inset = '0'; dialog.style.width = '100vw'; dialog.style.height = '100vh';
            dialog.style.alignItems = 'center'; dialog.style.justifyContent = 'center';
        }
    }
    icon(item) { const type = String(item.mimeType || ''); if (type.startsWith('image/')) return 'fa-file-image'; if (type.includes('pdf')) return 'fa-file-pdf'; if (type.includes('word')) return 'fa-file-word'; if (type.includes('sheet') || type.includes('excel')) return 'fa-file-excel'; if (type.includes('zip')) return 'fa-file-archive'; return 'fa-file-alt'; }
    typeLabel(item) { return String(item.mimeType || item.fileName?.split('.').pop() || 'File').split('/').pop().replace(/^vnd\./, '').replace(/[.-]/g, ' ').toUpperCase(); }
    title(value) { return String(value || '').replace(/_/g, ' ').replace(/\b\w/g, letter => letter.toUpperCase()); }
    date(value, time = false) { if (!value) return 'Not recorded'; const date = new Date(value.replace(' ', 'T') + 'Z'); if (Number.isNaN(date.getTime())) return this.escape(value); return new Intl.DateTimeFormat(undefined, {year: 'numeric', month: 'short', day: 'numeric', ...(time ? {hour: 'numeric', minute: '2-digit'} : {})}).format(date); }
    escape(value) { const node = document.createElement('div'); node.textContent = String(value ?? ''); return node.innerHTML; }
});
