define('custom:views/account/trash', ['view'], Dep => class extends Dep {
    template = 'custom:account/trash';

    events = {
        'click [data-action="back"]': 'actionBack',
        'click [data-action="clearFilters"]': 'actionClearFilters',
        'click [data-action="restore"]': 'actionRestore',
        'click [data-action="purge"]': 'actionPurge',
        'input [data-name="search"]': 'scheduleFilter',
        'change [data-name="dateFrom"]': 'applyFilters',
        'change [data-name="dateTo"]': 'applyFilters',
        'change [data-name="deletedBy"]': 'applyFilters',
        'change [data-name="selectAll"]': 'toggleAll',
        'change [data-name="record"]': 'updateSelection',
    };

    setup() {
        this.records = [];
        this.filteredRecords = [];
        this.selectedIds = new Set();
        this.setPageTitle('Restore Account Records');
    }

    afterRender() {
        super.afterRender();
        this.loadRecords();
    }

    actionBack() {
        this.getRouter().navigate('#Account', {trigger: true});
    }

    async loadRecords() {
        try {
            const result = await Espo.Ajax.getRequest('Nexa/account/trash');
            this.records = result.list || [];
            this.populateUsers(result.userList || []);
            this.applyFilters();
        } catch (error) {
            const body = this.element.querySelector('[data-name="records"]');
            if (body) body.innerHTML = '<tr><td colspan="4" class="nexa-restore-empty text-danger">Deleted account records could not be loaded.</td></tr>';
        }
    }

    populateUsers(users) {
        const select = this.element.querySelector('[data-name="deletedBy"]');
        if (!select) return;

        const all = document.createElement('option');
        all.value = '';
        all.textContent = 'All users';
        select.replaceChildren(all);
        users.sort((a, b) => a.name.localeCompare(b.name)).forEach(user => {
            const option = document.createElement('option');
            option.value = user.id;
            option.textContent = user.name;
            select.appendChild(option);
        });
    }

    scheduleFilter() {
        window.clearTimeout(this.filterTimer);
        this.filterTimer = window.setTimeout(() => this.applyFilters(), 120);
    }

    applyFilters() {
        const search = (this.element.querySelector('[data-name="search"]')?.value || '').trim().toLowerCase();
        const from = this.element.querySelector('[data-name="dateFrom"]')?.value || '';
        const to = this.element.querySelector('[data-name="dateTo"]')?.value || '';
        const deletedBy = this.element.querySelector('[data-name="deletedBy"]')?.value || '';

        this.filteredRecords = this.records.filter(record => {
            const text = `${record.name || ''} ${record.website || ''}`.toLowerCase();
            const date = (record.deletedAt || '').slice(0, 10);
            return (!search || text.includes(search)) && (!from || date >= from) &&
                (!to || date <= to) && (!deletedBy || record.deletedById === deletedBy);
        });
        this.selectedIds = new Set([...this.selectedIds].filter(id =>
            this.filteredRecords.some(record => record.id === id)));
        this.renderRecords();
    }

    renderRecords() {
        const body = this.element.querySelector('[data-name="records"]');
        const count = this.element.querySelector('[data-name="resultCount"]');
        if (!body || !count) return;

        body.replaceChildren();
        count.textContent = `${this.filteredRecords.length} ${this.filteredRecords.length === 1 ? 'record' : 'records'}`;
        if (!this.filteredRecords.length) {
            body.innerHTML = '<tr><td colspan="4" class="nexa-restore-empty">No deleted accounts match these filters.</td></tr>';
            this.updateSelection();
            return;
        }
        this.filteredRecords.forEach(record => body.appendChild(this.recordRow(record)));
        this.updateSelection();
    }

    recordRow(record) {
        const row = document.createElement('tr');
        const checkCell = document.createElement('td');
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.dataset.name = 'record';
        checkbox.value = record.id;
        checkbox.checked = this.selectedIds.has(record.id);
        checkbox.setAttribute('aria-label', `Select ${record.name || 'account'}`);
        checkCell.className = 'nexa-restore-check';
        checkCell.appendChild(checkbox);

        const nameCell = document.createElement('td');
        const name = document.createElement('strong');
        const website = document.createElement('span');
        name.textContent = record.name || 'Unnamed account';
        website.textContent = record.website || 'No website recorded';
        nameCell.className = 'nexa-restore-contact';
        nameCell.append(name, website);
        row.append(checkCell, nameCell, this.textCell(record.deletedByName || 'Unknown user'), this.textCell(this.formatDate(record.deletedAt)));
        return row;
    }

    textCell(value) {
        const cell = document.createElement('td');
        cell.textContent = value;
        return cell;
    }

    formatDate(value) {
        const date = value ? new Date(value.replace(' ', 'T') + 'Z') : null;
        return date && !Number.isNaN(date.getTime()) ? date.toLocaleString() : 'Not recorded';
    }

    toggleAll(event) {
        this.selectedIds = event.currentTarget.checked ? new Set(this.filteredRecords.map(record => record.id)) : new Set();
        this.renderRecords();
    }

    updateSelection() {
        this.element.querySelectorAll('[data-name="record"]').forEach(input => {
            input.checked ? this.selectedIds.add(input.value) : this.selectedIds.delete(input.value);
        });
        const selectAll = this.element.querySelector('[data-name="selectAll"]');
        const restore = this.element.querySelector('[data-action="restore"]');
        const purge = this.element.querySelector('[data-action="purge"]');
        if (selectAll) {
            selectAll.checked = this.filteredRecords.length > 0 && this.selectedIds.size === this.filteredRecords.length;
            selectAll.indeterminate = this.selectedIds.size > 0 && !selectAll.checked;
        }
        if (restore) restore.disabled = this.selectedIds.size === 0;
        if (purge) {
            purge.disabled = this.selectedIds.size === 0;
            purge.hidden = this.selectedIds.size === 0;
        }
    }

    actionClearFilters() {
        ['search', 'dateFrom', 'dateTo', 'deletedBy'].forEach(name => {
            const field = this.element.querySelector(`[data-name="${name}"]`);
            if (field) field.value = '';
        });
        this.applyFilters();
    }

    async actionRestore() {
        const ids = [...this.selectedIds];
        if (!ids.length) return;
        Espo.Ui.notify('Restoring account records...');
        try {
            const result = await Espo.Ajax.postRequest('Nexa/account/trash/restore', {ids});
            Espo.Ui.success(`${result.count} ${result.count === 1 ? 'account' : 'accounts'} restored.`);
            this.selectedIds.clear();
            await this.loadRecords();
            document.dispatchEvent(new CustomEvent('nexa:account-trash-changed'));
        } catch (error) {
            Espo.Ui.notify(false);
            Espo.Ui.error('The selected accounts could not be restored.');
        }
    }

    actionPurge() {
        const ids = [...this.selectedIds];
        if (!ids.length) return;
        this.createView('accountPurgeConfirmation', 'custom:views/account/modals/permanent-delete-confirmation', {
            count: ids.length,
        }, view => {
            view.render();
            this.listenToOnce(view, 'confirm', () => this.purgeRecords(ids));
        });
    }

    async purgeRecords(ids) {
        Espo.Ui.notify('Permanently deleting account records...');
        try {
            const result = await Espo.Ajax.postRequest('Nexa/account/trash/purge', {ids});
            Espo.Ui.success(`${result.count} ${result.count === 1 ? 'account' : 'accounts'} permanently deleted.`);
            this.selectedIds.clear();
            await this.loadRecords();
            document.dispatchEvent(new CustomEvent('nexa:account-trash-changed'));
        } catch (error) {
            Espo.Ui.notify(false);
            Espo.Ui.error('The selected accounts could not be permanently deleted.');
        }
    }
});
