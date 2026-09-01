define('custom:views/case/list-v2', ['views/list'], Dep => class extends Dep {
    searchView = 'custom:views/case/record/search-live';
    recordView = 'custom:views/case/record/list-infinite';
    setup() {
        super.setup();
        this.once('remove', () => {
            this.controlsObserver?.disconnect();
            document.body.classList.remove('nexa-case-list-page');
            this.caseListElement?.classList.remove('nexa-case-list-workspace');
            this.caseListElement = null;
        });
    }
    prepareRecordViewOptions(options) { super.prepareRecordViewOptions(options); options.pagination = false; options.showMore = true; }
    afterRender() {
        const result = super.afterRender();
        document.body.classList.add('nexa-case-list-page');
        this.caseListElement = this.element;
        this.caseListElement?.classList.add('nexa-case-list-workspace');
        this.decorateControls();
        this.observeControls();
        return result;
    }
    observeControls() {
        this.controlsObserver?.disconnect();
        this.controlsObserver = new MutationObserver(() => this.decorateControls());
        if (this.element) this.controlsObserver.observe(this.element, {childList:true, subtree:true});
    }
    decorateControls() {
        const root = this.element;
        if (!root) return;
        root.parentElement?.querySelector('.nexa-case-live-search input[data-name="textFilter"]')
            ?.setAttribute('placeholder', 'Search by Case ID, subject, category or description');
        const create = root.querySelector('.page-header .header-buttons [data-action="create"], .page-header .header-buttons [data-name="create"]');
        if (create && !create.classList.contains('nexa-case-create-button')) {
            create.classList.add('nexa-case-create-button');
            create.innerHTML = '<span class="fas fa-plus" aria-hidden="true"></span><span>New Case</span>';
        }
        const settings = root.querySelector('.settings-container .dropdown-toggle');
        if (settings && !settings.classList.contains('nexa-case-column-selector')) {
            settings.classList.add('nexa-case-column-selector');
            settings.title = 'Choose visible columns'; settings.setAttribute('aria-label', 'Choose visible Case columns');
            settings.innerHTML = '<span class="fas fa-columns" aria-hidden="true"></span><span>Columns</span><span class="caret" aria-hidden="true"></span>';
        }
        const total = root.querySelector('.total-count');
        if (total && !total.querySelector('.nexa-total-label')) {
            const label = document.createElement('span'); label.className = 'nexa-total-label'; label.textContent = 'Total cases:'; total.prepend(label);
        }
        const toolbar = settings?.closest('.nexa-list-toolbar');
        const settingsContainer = settings?.closest('.settings-container');
        if (toolbar && settingsContainer && total && settingsContainer.nextElementSibling !== total) {
            toolbar.insertBefore(total, settingsContainer.nextElementSibling);
        }
        if (this.getUser().isAdmin() && create && !root.querySelector('[data-nexa-sla-settings]')) {
            const button = document.createElement('button');
            button.type = 'button'; button.className = 'btn btn-default nexa-case-sla-settings'; button.dataset.nexaSlaSettings = 'true';
            button.innerHTML = '<span class="fas fa-stopwatch" aria-hidden="true"></span><span>SLA policies</span>';
            button.addEventListener('click', () => this.openSlaPolicies()); create.before(button);
        }
    }
    async openSlaPolicies() {
        try {
            const response = await Espo.Ajax.getRequest('Nexa/cases/sla-policies');
            const policies = response.list || [];
            const modal = document.createElement('div'); modal.className = 'nexa-case-modal-backdrop';
            modal.innerHTML = `<section class="nexa-case-policy-dialog" role="dialog" aria-modal="true" aria-labelledby="nexa-sla-title">
                <header><div><p>Tenant service settings</p><h2 id="nexa-sla-title">Case SLA policies</h2></div><button type="button" data-close aria-label="Close"><span class="fas fa-times"></span></button></header>
                <p class="nexa-case-policy-copy">Deadlines apply automatically to Cases created by staff, the customer portal, or inbound email.</p>
                <div class="nexa-case-policy-list">${policies.map((policy, index) => this.policyRow(policy, index)).join('')}</div>
                <footer><button type="button" class="btn btn-primary" data-save>Save policies</button><button type="button" class="btn btn-default" data-close>Cancel</button></footer>
            </section>`;
            document.body.append(modal); this.policyModal = modal;
            modal.querySelectorAll('[data-close]').forEach(button => button.addEventListener('click', () => this.closePolicyModal()));
            modal.querySelector('[data-save]').addEventListener('click', () => this.saveSlaPolicies(modal));
            modal.querySelector('input')?.focus();
        } catch (error) { Espo.Ui.error(error?.message || 'SLA policies could not be loaded.'); }
    }
    policyRow(policy, index) {
        const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
        return `<div class="nexa-case-policy-row" data-policy data-id="${esc(policy.id)}">
            <label>Name<input class="form-control" data-field="name" value="${esc(policy.name)}"></label>
            <label>Priority<select class="form-control" data-field="priority">${['Urgent','High','Normal','Low'].map(value => `<option ${policy.priority === value ? 'selected' : ''}>${value}</option>`).join('')}</select></label>
            <label>First response (minutes)<input class="form-control" type="number" min="1" data-field="firstResponseMinutes" value="${Number(policy.firstResponseMinutes)}"></label>
            <label>Resolution (minutes)<input class="form-control" type="number" min="1" data-field="resolutionMinutes" value="${Number(policy.resolutionMinutes)}"></label>
            <label class="nexa-case-policy-default"><input type="radio" name="default-policy" ${policy.isDefault || (!index && !policy.isDefault) ? 'checked' : ''}> Default</label>
        </div>`;
    }
    async saveSlaPolicies(modal) {
        const policies = [...modal.querySelectorAll('[data-policy]')].map(row => ({
            id: row.dataset.id, name: row.querySelector('[data-field="name"]').value.trim(),
            priority: row.querySelector('[data-field="priority"]').value,
            firstResponseMinutes: Number(row.querySelector('[data-field="firstResponseMinutes"]').value),
            resolutionMinutes: Number(row.querySelector('[data-field="resolutionMinutes"]').value),
            isDefault: row.querySelector('input[type="radio"]').checked,
        }));
        Espo.Ui.notify('Saving SLA policies...');
        try { await Espo.Ajax.putRequest('Nexa/cases/sla-policies', {policies}); Espo.Ui.success('SLA policies saved.'); this.closePolicyModal(); }
        catch (error) { Espo.Ui.notify(false); Espo.Ui.error(error?.message || 'SLA policies could not be saved.'); }
    }
    closePolicyModal() { this.policyModal?.remove(); this.policyModal = null; }
});
