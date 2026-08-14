define('custom:views/contact/export-audit', ['view'], Dep => class extends Dep {
    template = 'custom:contact/export-audit';

    events = {
        'click [data-action="back"]': 'actionBack',
        'click [data-action="import"]': 'actionImport',
        'click [data-action="refresh"]': 'loadAudits',
        'click [data-action="download"]': 'actionDownload',
        'click [data-action="delete"]': 'actionDelete',
    };

    setup() {
        this.setPageTitle('Import and Export');
    }

    afterRender() {
        super.afterRender();
        this.loadAudits();
    }

    actionBack() {
        this.getRouter().navigate('#Contact', {trigger: true});
    }

    actionImport() {
        this.getRouter().navigate('#Contact/import', {trigger: true});
    }

    async actionDownload(event) {
        const id = event.currentTarget.dataset.id;
        if (!id || event.currentTarget.dataset.expired === 'true') return;

        event.currentTarget.disabled = true;

        try {
            const result = await Espo.Ajax.getRequest(
                `Nexa/contact-export/${encodeURIComponent(id)}/download`
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
        } catch (error) {
            Espo.Ui.error('The export file could not be downloaded.');
        } finally {
            event.currentTarget.disabled = false;
        }
    }

    async loadAudits() {
        const body = this.element?.querySelector('[data-name="auditRows"]');
        if (!body) return;

        body.innerHTML = '<tr><td colspan="6" class="nexa-export-empty">Loading exports...</td></tr>';

        try {
            const result = await Espo.Ajax.getRequest('Nexa/contact-export/audit');
            this.renderAudits(body, result.list || []);
        } catch (error) {
            body.innerHTML = '<tr><td colspan="6" class="nexa-export-empty text-danger">Exports could not be loaded.</td></tr>';
        }
    }

    renderAudits(body, rows) {
        body.replaceChildren();

        if (!rows.length) {
            body.innerHTML = '<tr><td colspan="6" class="nexa-export-empty">No contact exports yet.</td></tr>';
            return;
        }

        rows.forEach(row => {
            const tr = document.createElement('tr');
            const nameCell = document.createElement('td');
            const download = document.createElement('button');
            download.type = 'button';
            download.className = 'btn btn-link nexa-export-download';
            download.dataset.action = 'download';
            download.dataset.id = row.id;
            download.dataset.expired = String(Boolean(row.expired));
            download.disabled = Boolean(row.expired);
            download.textContent = row.name;
            nameCell.append(download, this.statusElement(row));
            tr.append(
                nameCell,
                this.textCell(row.source),
                this.textCell(Number(row.count).toLocaleString()),
                this.userCell(row),
                this.dateCell(row.createdAt),
                this.actionCell(row),
            );
            body.appendChild(tr);
        });
    }

    statusElement(row) {
        const status = document.createElement('span');
        status.className = `nexa-export-status ${row.expired ? 'is-expired' : 'is-ready'}`;
        status.textContent = row.expired ? 'Expired' : 'Download available';
        return status;
    }

    textCell(value) {
        const td = document.createElement('td');
        td.textContent = value || '-';
        return td;
    }

    userCell(row) {
        const td = document.createElement('td');
        const name = document.createElement('strong');
        const email = document.createElement('span');
        name.textContent = row.userName || 'Unknown user';
        email.textContent = row.userEmail || '';
        td.append(name, email);
        return td;
    }

    dateCell(value) {
        const td = document.createElement('td');
        const date = value ? new Date(value.replace(' ', 'T') + 'Z') : null;
        td.textContent = date && !Number.isNaN(date.getTime()) ? date.toLocaleString() : (value || '-');
        return td;
    }

    actionCell(row) {
        const td = document.createElement('td');
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-link btn-icon nexa-export-delete';
        button.dataset.action = 'delete';
        button.dataset.id = row.id;
        button.dataset.name = row.name;
        button.title = `Delete ${row.name}`;
        button.setAttribute('aria-label', `Delete ${row.name}`);
        button.innerHTML = '<span class="fas fa-trash-alt" aria-hidden="true"></span>';
        td.className = 'nexa-export-actions';
        td.appendChild(button);
        return td;
    }

    actionDelete(event) {
        const {id, name} = event.currentTarget.dataset;
        if (!id) return;

        this.createView('exportDeleteConfirmation', 'custom:views/contact/modals/export-delete-confirmation', {
            name,
        }, view => {
            view.render();
            this.listenToOnce(view, 'confirm', () => this.deleteExport(id));
        });
    }

    async deleteExport(id) {
        Espo.Ui.notify('Deleting export...');

        try {
            await Espo.Ajax.deleteRequest(`Nexa/contact-export/${encodeURIComponent(id)}`);
            Espo.Ui.success('Export deleted.');
            await this.loadAudits();
        } catch (error) {
            Espo.Ui.notify(false);
            Espo.Ui.error('The export could not be deleted.');
        }
    }
});
