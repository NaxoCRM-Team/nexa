define('custom:views/user/record/detail', ['views/user/record/detail', 'custom:views/call/caller-id-modal'], (Dep, CallerIdVerifyModal) => class extends Dep {
    afterRender() {
        super.afterRender();

        const isOwnProfile = this.model.id === this.getUser().id;
        this.element.querySelector('[data-nexa-company-panel]')?.remove();

        if (!isOwnProfile) return;

        this.renderCompanyPanel();
    }

    renderCompanyPanel() {
        const tenant = this.getHelper().getAppParam('nexaTenant') || {};
        const isAdmin = this.getUser().isAdmin();

        const panel = document.createElement('div');
        panel.className = 'panel panel-default nexa-company-panel';
        panel.dataset.nexaCompanyPanel = '';
        panel.innerHTML = `
            <div class="panel-heading"><h4 class="panel-title">Company</h4></div>
            <div class="panel-body">
                <div class="nexa-company-row">
                    <label>Company name</label>
                    <div class="nexa-company-value" data-nexa-company-name-display></div>
                </div>
                <div class="nexa-company-row">
                    <label>Phone number</label>
                    <div class="nexa-company-value" data-nexa-company-phone-display></div>
                </div>
                <p class="nexa-company-note">More company information will be added here later.</p>
            </div>`;

        const container = this.element.querySelector('.record-detail, .detail') || this.element;
        container.prepend(panel);

        this.renderCompanyName(panel, tenant, isAdmin);
        this.renderCompanyPhone(panel, isAdmin);
    }

    renderCompanyName(panel, tenant, isAdmin) {
        const holder = panel.querySelector('[data-nexa-company-name-display]');
        if (!holder) return;

        if (!isAdmin) {
            holder.textContent = tenant.displayName || 'Not set';
            return;
        }

        holder.innerHTML = `
            <input class="form-control" type="text" maxlength="190" value="${this.escapeHtml(tenant.displayName || '')}" data-nexa-company-name-input>
            <button type="button" class="btn btn-default btn-sm" data-nexa-company-name-save>Save</button>`;

        holder.querySelector('[data-nexa-company-name-save]').addEventListener('click', () => this.saveCompanyName(holder, tenant));
    }

    async saveCompanyName(holder, tenant) {
        const input = holder.querySelector('[data-nexa-company-name-input]');
        const button = holder.querySelector('[data-nexa-company-name-save]');
        const displayName = input.value.trim();

        if (!displayName) {
            Espo.Ui.error('Enter a company name.');
            return;
        }

        button.disabled = true;
        try {
            await Espo.Ajax.postRequest('Nexa/tenant/company-name', {displayName});
            tenant.displayName = displayName;
            Espo.Ui.success('Company name saved');
        } catch (error) {
            Espo.Ui.error('Could not save the company name.');
        } finally {
            button.disabled = false;
        }
    }

    async renderCompanyPhone(panel, isAdmin) {
        const holder = panel.querySelector('[data-nexa-company-phone-display]');
        if (!holder) return;

        holder.textContent = 'Loading…';

        let status;
        try {
            status = await Espo.Ajax.getRequest('Nexa/call/caller-id');
        } catch (error) {
            holder.textContent = 'Unavailable';
            return;
        }

        const badgeClass = {verified: 'is-verified', pending: 'is-pending', unverified: 'is-unverified'}[status.status] || 'is-unverified';
        const badgeLabel = {verified: 'Verified', pending: 'Pending verification', unverified: 'Not verified'}[status.status] || 'Not verified';
        const numberText = status.callerNumber || 'Not set';

        holder.innerHTML = `
            <span>${this.escapeHtml(numberText)}</span>
            <span class="label nexa-phone-status-badge ${badgeClass}">${badgeLabel}</span>
            ${isAdmin ? '<button type="button" class="btn btn-default btn-sm" data-nexa-company-phone-edit>Edit phone</button>' : ''}`;

        if (isAdmin) {
            holder.querySelector('[data-nexa-company-phone-edit]').addEventListener('click', () => {
                const modal = new CallerIdVerifyModal({onVerified: () => this.renderCompanyPhone(panel, isAdmin)});
                modal.open(status.callerNumber || '');
            });
        }
    }

    escapeHtml(value) {
        const node = document.createElement('span');
        node.textContent = String(value ?? '');
        return node.innerHTML;
    }
});
