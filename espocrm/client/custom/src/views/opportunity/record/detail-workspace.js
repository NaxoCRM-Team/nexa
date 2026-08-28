define('custom:views/opportunity/record/detail-workspace', ['crm:views/opportunity/record/detail'], Dep => class extends Dep {
    setup() {
        super.setup();
        this.listenTo(this.model, 'sync change', () => this.refreshWorkspace());
        this.once('remove', () => document.body.classList.remove('nexa-opportunity-detail-page'));
    }

    afterRender() {
        const result = super.afterRender();
        document.body.classList.add('nexa-opportunity-detail-page');
        this.element?.classList.add('nexa-opportunity-record');
        this.prepareWorkspace();
        return result;
    }

    async prepareWorkspace() {
        if (this.element?.querySelector('[data-nexa-opportunity-workspace]')) return;
        const detail = this.element?.querySelector(':scope > .detail') || this.element?.querySelector('.detail');
        if (!detail) return;
        const shell = document.createElement('section');
        shell.className = 'nexa-opportunity-workspace';
        shell.dataset.nexaOpportunityWorkspace = 'true';
        shell.innerHTML = `<header class="nexa-opportunity-hero"><div class="nexa-opportunity-identity"><a href="#Opportunity" aria-label="Back to Opportunities"><span class="fas fa-arrow-left"></span></a><span class="nexa-opportunity-mark"><span class="fas fa-handshake"></span></span><div><p>Sales opportunity</p><h1 data-field="name"></h1><span data-field="account"></span></div></div><div data-native-actions></div></header>
            <section class="nexa-opportunity-metrics"><article><small>Deal amount</small><strong data-field="amount"></strong></article><article><small>Weighted revenue</small><strong data-field="expectedRevenue"></strong></article><article><small>Probability</small><strong data-field="probability"></strong></article><article><small>Target close</small><strong data-field="closeDate"></strong></article></section>
            <div class="nexa-opportunity-grid"><main><section class="nexa-opportunity-card"><div class="nexa-card-heading"><div><p>Deal progress</p><h2>Pipeline and forecast</h2></div></div><div class="nexa-opportunity-stage"><span data-field="stage"></span><span data-field="forecastCategory"></span></div><dl>${this.fact('Pipeline', 'pipelineId')}${this.fact('Next step', 'nextStep')}${this.fact('Lead source', 'leadSource')}${this.fact('Campaign', 'campaign')}${this.fact('Owner', 'assignedUsers')}</dl></section><section class="nexa-opportunity-card"><div class="nexa-card-heading"><div><p>Revenue history</p><h2>Forecast movement</h2></div></div><div data-revenue-history class="nexa-opportunity-history"><span class="fas fa-circle-notch fa-spin"></span> Loading history...</div></section></main>
            <aside><section class="nexa-opportunity-card"><div class="nexa-card-heading"><div><p>Customer</p><h2>Connected records</h2></div></div><dl>${this.fact('Account', 'account')}${this.fact('Primary contact', 'contact')}${this.fact('Contacts', 'contacts')}</dl></section><section class="nexa-opportunity-card"><div class="nexa-card-heading nexa-commercial-heading"><div><p>Commercial</p><h2>Products and quotes</h2></div><div data-commercial-actions><button class="btn btn-default btn-sm" data-action="addProduct"><span class="fas fa-plus"></span> Product</button><button class="btn btn-default btn-sm" data-action="createQuote"><span class="fas fa-file-invoice-dollar"></span> Quote</button></div></div><div data-line-items></div><div data-quotes></div><a class="btn btn-default btn-block" href="#NexaSales/products">Open product catalogue</a></section></aside></div>
            ${this.productModal()}${this.quoteModal()}`;
        detail.prepend(shell);
        [...this.element.querySelectorAll(':scope > .record-buttons, :scope > .edit-buttons, .detail > .record-buttons')].forEach(node => shell.querySelector('[data-native-actions]').append(node));
        detail.querySelector(':scope > .record-grid')?.classList.add('nexa-native-opportunity-record');
        this.bindCommercial(shell);
        this.refreshWorkspace();
        await this.loadCommercial(shell);
    }

    productModal() {
        return `<div class="nexa-sales-modal" data-sales-modal="product" hidden><div class="nexa-sales-modal-card" role="dialog" aria-modal="true" aria-labelledby="nexa-add-product-heading"><header><h2 id="nexa-add-product-heading" data-line-form-title>Add product</h2><button class="btn btn-icon" type="button" data-close-modal aria-label="Close"><span class="fas fa-times"></span></button></header><form data-line-item-form><input type="hidden" name="lineId"><label>Product<select class="form-control" name="productId" required></select></label><div class="nexa-sales-form-row"><label>Quantity<input class="form-control" name="quantity" type="number" min="0.001" step="0.001" value="1" required></label><label>Unit price<input class="form-control" name="unitPrice" type="number" min="0" step="0.01" required></label></div><div class="nexa-sales-form-row"><label>Discount %<input class="form-control" name="discountPercent" type="number" min="0" max="100" step="0.001" value="0"></label><label>Tax %<input class="form-control" name="taxPercent" type="number" min="0" max="100" step="0.001" value="0"></label></div><label>Opportunity currency<input class="form-control" name="currency" maxlength="3" required readonly><small>Line items and quotes inherit the Opportunity currency.</small></label><footer><button class="btn btn-primary" type="submit" data-line-submit>Add to opportunity</button><button class="btn btn-default" type="button" data-close-modal>Cancel</button></footer></form></div></div>`;
    }

    quoteModal() {
        return `<div class="nexa-sales-modal" data-sales-modal="quote" hidden><div class="nexa-sales-modal-card" role="dialog" aria-modal="true" aria-labelledby="nexa-create-quote-heading"><header><h2 id="nexa-create-quote-heading">Create quote</h2><button class="btn btn-icon" type="button" data-close-modal aria-label="Close"><span class="fas fa-times"></span></button></header><form data-quote-form><label>Quote name<input class="form-control" name="name" maxlength="200" required></label><label>Valid until<input class="form-control" name="validUntil" type="date"></label><p>The current products, prices and discounts will be preserved in this quote.</p><footer><button class="btn btn-primary" type="submit">Create quote</button><button class="btn btn-default" type="button" data-close-modal>Cancel</button></footer></form></div></div>`;
    }

    bindCommercial(shell) {
        shell.querySelector('[data-action="addProduct"]').addEventListener('click', () => this.openModal(shell, 'product'));
        shell.querySelector('[data-action="createQuote"]').addEventListener('click', () => this.openModal(shell, 'quote'));
        shell.querySelectorAll('[data-close-modal]').forEach(button => button.addEventListener('click', () => this.closeModals(shell)));
        shell.querySelector('[data-line-item-form]').addEventListener('submit', event => this.addLineItem(event, shell));
        shell.querySelector('[data-quote-form]').addEventListener('submit', event => this.createQuote(event, shell));
        shell.querySelector('[data-line-item-form] [name="productId"]').addEventListener('change', event => this.populateProductFields(event.currentTarget, shell));
        shell.addEventListener('click', event => {
            const edit = event.target.closest('[data-action="editLineItem"]'), remove = event.target.closest('[data-action="removeLineItem"]'), status = event.target.closest('[data-action="quoteStatus"]');
            if (edit) this.editLineItem(edit.dataset.lineId, shell);
            if (remove) this.removeLineItem(remove.dataset.lineId, shell);
            if (status) this.updateQuoteStatus(status.dataset.quoteId, status.dataset.status, shell);
        });
        shell.addEventListener('change', event => {
            const select = event.target.closest('[data-quote-status-select]');
            if (select) shell.querySelector(`[data-quote-status-button][data-quote-id="${CSS.escape(select.dataset.quoteId)}"]`).dataset.status = select.value;
        });
    }

    async loadCommercial(shell) {
        try { this.salesData = await Espo.Ajax.getRequest(`Nexa/sales/opportunity/${encodeURIComponent(this.model.id)}`); }
        catch (error) { this.salesData = {}; }
        if (!this.isRendered()) return;
        const pipeline = (this.salesData.pipelines || []).find(item => item.id === this.model.get('pipelineId'));
        shell.querySelector('[data-field="pipelineId"]').textContent = pipeline?.name || 'Default sales pipeline';
        this.renderCommercial(shell);
    }

    openModal(shell, name) {
        const modal = shell.querySelector(`[data-sales-modal="${name}"]`);
        if (name === 'product') {
            const form = shell.querySelector('[data-line-item-form]'); form.reset(); form.elements.lineId.value = ''; form.elements.productId.disabled = false;
            shell.querySelector('[data-line-form-title]').textContent = 'Add product'; shell.querySelector('[data-line-submit]').textContent = 'Add to opportunity'; this.populateProductSelect(shell);
        }
        if (name === 'quote' && !(this.salesData?.lineItems || []).length) return Espo.Ui.warning('Add a product before creating a quote.');
        modal.hidden = false;
        modal.querySelector('input, select')?.focus();
    }

    closeModals(shell) { shell.querySelectorAll('[data-sales-modal]').forEach(modal => { modal.hidden = true; }); }

    populateProductSelect(shell) {
        const select = shell.querySelector('[data-line-item-form] [name="productId"]');
        const products = this.salesData?.products || [];
        select.innerHTML = '<option value="">Choose a product</option>' + products.map(item => `<option value="${this.escape(item.id)}">${this.escape(item.name)} (${this.escape(item.sku)})</option>`).join('');
        this.populateProductFields(select, shell);
    }

    populateProductFields(select, shell) {
        const product = (this.salesData?.products || []).find(item => item.id === select.value);
        const form = shell.querySelector('[data-line-item-form]');
        const settings = this.salesData?.currencySettings || {};
        const target = this.model.get('amountCurrency') || settings.defaultCurrency || 'USD';
        form.elements.currency.value = target;
        form.elements.unitPrice.value = product ? this.convertAmount(product.unitPrice, product.currency, target, settings.rates || {}) : '';
        form.elements.taxPercent.value = product?.taxPercent ?? 0;
    }

    convertAmount(value, source, target, rates) {
        if (!source || source === target) return Number(value || 0).toFixed(2);
        const sourceRate = Number(rates[source]), targetRate = Number(rates[target]);
        if (!(sourceRate > 0) || !(targetRate > 0)) { Espo.Ui.error(`Configure exchange rates for ${source} and ${target} before adding this product.`); return ''; }
        return (Number(value || 0) * sourceRate / targetRate).toFixed(2);
    }

    async addLineItem(event, shell) {
        event.preventDefault();
        const form = event.currentTarget;
        this.disableForm(form, true);
        try {
            const body = Object.fromEntries(new FormData(form).entries()), lineId = body.lineId; delete body.lineId;
            this.salesData = await Espo.Ajax.postRequest(`Nexa/sales/opportunity/${encodeURIComponent(this.model.id)}/line-items${lineId ? `/${encodeURIComponent(lineId)}` : ''}`, body);
            this.closeModals(shell); form.reset(); this.renderCommercial(shell); await this.model.fetch(); Espo.Ui.success(lineId ? 'Line item updated.' : 'Product added.');
        } catch (error) { Espo.Ui.error(error?.message || 'The product could not be added.'); }
        finally { this.disableForm(form, false); }
    }

    editLineItem(id, shell) {
        const item = (this.salesData?.lineItems || []).find(row => row.id === id), form = shell.querySelector('[data-line-item-form]'); if (!item) return;
        this.populateProductSelect(shell); form.elements.lineId.value = item.id; form.elements.productId.value = item.product_id || ''; form.elements.productId.disabled = true;
        form.elements.quantity.value = item.quantity; form.elements.unitPrice.value = item.unit_price; form.elements.discountPercent.value = item.discount_percent; form.elements.taxPercent.value = item.tax_percent || 0; form.elements.currency.value = item.currency;
        shell.querySelector('[data-line-form-title]').textContent = 'Edit line item'; shell.querySelector('[data-line-submit]').textContent = 'Save line item'; shell.querySelector('[data-sales-modal="product"]').hidden = false;
    }

    async removeLineItem(id, shell) {
        if (!confirm('Remove this line item from the opportunity? Existing quote snapshots will not change.')) return;
        try { this.salesData = await Espo.Ajax.deleteRequest(`Nexa/sales/opportunity/${encodeURIComponent(this.model.id)}/line-items/${encodeURIComponent(id)}`); this.renderCommercial(shell); await this.model.fetch(); Espo.Ui.success('Line item removed.'); }
        catch (error) { Espo.Ui.error(error?.message || 'Line item could not be removed.'); }
    }

    async updateQuoteStatus(id, status, shell) {
        if (!status) return Espo.Ui.warning('Choose the next quote status.');
        if (!confirm(`Move this quote to ${status}?`)) return;
        try { await Espo.Ajax.postRequest(`Nexa/sales/quotes/${encodeURIComponent(id)}/status`, {status}); await this.loadCommercial(shell); Espo.Ui.success(`Quote marked ${status}.`); }
        catch (error) { Espo.Ui.error(error?.message || 'Quote status could not be changed.'); }
    }

    async createQuote(event, shell) {
        event.preventDefault();
        const form = event.currentTarget;
        this.disableForm(form, true);
        try {
            await Espo.Ajax.postRequest(`Nexa/sales/opportunity/${encodeURIComponent(this.model.id)}/quotes`, Object.fromEntries(new FormData(form).entries()));
            this.closeModals(shell); form.reset(); await this.loadCommercial(shell); Espo.Ui.success('Quote created.');
        } catch (error) { Espo.Ui.error(error?.message || 'The quote could not be created.'); }
        finally { this.disableForm(form, false); }
    }

    disableForm(form, disabled) { form.querySelectorAll('input, select, button').forEach(node => { node.disabled = disabled; }); }
    fact(label, field) { return `<div><dt>${label}</dt><dd data-field="${field}"></dd></div>`; }

    refreshWorkspace() {
        const shell = this.element?.querySelector('[data-nexa-opportunity-workspace]'); if (!shell) return;
        const values = {
            name: this.model.get('name') || 'Unnamed opportunity', account: this.model.get('accountName') || 'No account connected',
            amount: this.money(this.model.get('amount'), this.model.get('amountCurrency')), expectedRevenue: this.money(this.model.get('expectedRevenue'), this.model.get('amountCurrency')),
            probability: `${this.model.get('probability') ?? 0}%`, closeDate: this.model.get('closeDate') || 'Not scheduled', stage: this.model.get('stage') || 'Unassigned',
            forecastCategory: this.model.get('forecastCategory') || 'Pipeline', pipelineId: 'Default sales pipeline', nextStep: this.model.get('nextStep') || 'Not recorded',
            leadSource: this.model.get('leadSource') || 'Not recorded', campaign: this.model.get('campaignName') || 'Not connected', assignedUsers: this.names(this.model.get('assignedUsersNames'), 'Unassigned'),
            contact: this.model.get('contactName') || 'Not connected', contacts: this.names(this.model.get('contactsNames'), 'No contacts connected')
        };
        Object.entries(values).forEach(([field, value]) => shell.querySelectorAll(`[data-field="${field}"]`).forEach(node => { node.textContent = value; }));
    }

    renderCommercial(shell) {
        const lines = this.salesData?.lineItems || [], quotes = this.salesData?.quotes || [], history = this.salesData?.revenueHistory || [];
        shell.querySelector('[data-commercial-actions]').hidden = !this.salesData?.permissions?.edit;
        shell.querySelector('[data-line-items]').innerHTML = lines.length ? `<h3>Line items</h3>${lines.map(item => `<div class="nexa-commercial-row"><span>${this.escape(item.name)} <small>${Number(item.quantity).toLocaleString()} &times; ${this.money(item.unit_price, item.currency)} &middot; ${Number(item.discount_percent)}% discount &middot; ${Number(item.tax_percent || 0)}% tax</small></span><strong>${this.money(Number(item.quantity) * Number(item.unit_price) * (1 - Number(item.discount_percent) / 100) * (1 + Number(item.tax_percent || 0) / 100), item.currency)}</strong>${this.salesData?.permissions?.edit ? `<div><button class="btn btn-icon" data-action="editLineItem" data-line-id="${this.escape(item.id)}" title="Edit line item"><span class="fas fa-pen"></span></button><button class="btn btn-icon text-danger" data-action="removeLineItem" data-line-id="${this.escape(item.id)}" title="Remove line item"><span class="fas fa-trash"></span></button></div>` : ''}</div>`).join('')}` : '<p class="nexa-empty-copy">No products have been added to this opportunity.</p>';
        const transitions = {Draft: ['Pending Approval', 'Sent', 'Expired', 'Cancelled'], 'Pending Approval': ['Approved', 'Rejected', 'Draft', 'Cancelled'], Approved: ['Sent', 'Cancelled'], Sent: ['Accepted', 'Rejected', 'Expired', 'Cancelled'], Rejected: ['Draft', 'Cancelled'], Expired: ['Draft', 'Cancelled']};
        shell.querySelector('[data-quotes]').innerHTML = quotes.length ? `<h3>Quotes</h3>${quotes.map(item => `<div class="nexa-commercial-row"><span>${this.escape(item.quote_number)} &middot; <b>${this.escape(item.status)}</b></span><strong>${this.money(item.grand_total, item.currency)}</strong>${this.salesData?.permissions?.edit && (transitions[item.status] || []).length ? `<select class="form-control input-sm" data-quote-status-select data-quote-id="${this.escape(item.id)}" aria-label="Change quote status"><option value="">Change status</option>${transitions[item.status].map(status => `<option>${status}</option>`).join('')}</select><button class="btn btn-default btn-sm" data-action="quoteStatus" data-quote-status-button data-quote-id="${this.escape(item.id)}" data-status="">Apply</button>` : ''}</div>`).join('')}` : '';
        shell.querySelector('[data-revenue-history]').innerHTML = history.length ? history.map(item => `<article><span class="fas fa-chart-line"></span><div><strong>${this.escape(item.stage || 'Updated')}</strong><small>${this.money(item.expected_revenue, item.currency)} weighted &middot; ${this.escape(item.changed_at)}</small></div></article>`).join('') : '<p class="nexa-empty-copy">Revenue changes will appear here after this opportunity is updated.</p>';
    }

    money(value, currency) { const amount = Number(value || 0); try { return new Intl.NumberFormat(undefined, {style: 'currency', currency: currency || 'USD', maximumFractionDigits: 2}).format(amount); } catch { return `${currency || ''} ${amount.toLocaleString()}`.trim(); } }
    names(value, fallback) { const names = Array.isArray(value) ? value : Object.values(value || {}); return names.filter(Boolean).join(', ') || fallback; }
    escape(value) { const node = document.createElement('span'); node.textContent = String(value ?? ''); return node.innerHTML; }
});
