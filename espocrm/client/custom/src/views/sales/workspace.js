define('custom:views/sales/workspace', ['view'], Dep => class extends Dep {
    template = 'custom:sales/workspace';
    events = {
        'change [data-sales-range]': 'changeRange', 'change [data-pipeline-select]': 'changePipeline',
        'change [data-forecast-filter]': 'changeForecastFilter', 'click [data-action="refreshSales"]': 'loadData',
        'click [data-action="editPipeline"]': 'editPipeline', 'click [data-action="archivePipeline"]': 'archivePipeline',
        'click [data-action="movePipeline"]': 'movePipeline', 'click [data-action="editStage"]': 'toggleStage',
        'click [data-action="archiveStage"]': 'archiveStage', 'click [data-action="moveStage"]': 'moveStage',
        'click [data-action="editProduct"]': 'editProduct', 'click [data-action="archiveProduct"]': 'archiveProduct',
        'click [data-action="cancelProductEdit"]': 'cancelProductEdit', 'submit [data-stage-form]': 'updateStage',
        'submit [data-stage-create-form]': 'createStage', 'submit [data-pipeline-form]': 'createPipeline',
        'submit [data-product-form]': 'saveProduct',
    };

    setup() { this.section = this.options.section || 'pipelines'; this.range = 'quarter'; this.selectedPipelineId = null; this.filters = {pipelineId: '', ownerId: '', teamId: ''}; this.workspaceData = null; }
    data() { return {section: this.section, isPipelines: this.section === 'pipelines', isForecasts: this.section === 'forecasts', isProducts: this.section === 'products'}; }
    afterRender() { this.element?.classList.add('nexa-sales-workspace-page'); this.loadData(); }

    async loadData() {
        this.setState('loading');
        try {
            this.workspaceData = await Espo.Ajax.getRequest('Nexa/sales/workspace', {range: this.range, ...this.filters});
            if (!(this.workspaceData.pipelines || []).some(item => item.id === this.selectedPipelineId)) this.selectedPipelineId = this.workspaceData.defaultPipelineId;
            this.renderData(); this.setState('ready');
        } catch (error) { this.setState(error?.status === 403 ? 'denied' : 'error'); }
    }

    changeRange(event) { this.range = event.currentTarget.value; this.loadData(); }
    changePipeline(event) { this.selectedPipelineId = event.currentTarget.value; this.renderPipelines(this.workspaceData, this.moneyFormatter(this.workspaceData?.currency)); }
    changeForecastFilter(event) { this.filters[event.currentTarget.name] = event.currentTarget.value; this.loadData(); }
    setState(state) { this.element?.querySelectorAll('[data-sales-state]').forEach(node => { node.hidden = node.dataset.salesState !== state; }); }

    renderData() {
        const data = this.workspaceData || {}, money = this.moneyFormatter(data.currency);
        const totalMap = {openAmount: 'Open pipeline', weightedAmount: 'Weighted forecast', wonAmount: 'Closed won', count: 'Opportunities'};
        this.element.querySelector('[data-sales-metrics]').innerHTML = Object.entries(totalMap).map(([key, label]) => `<article><small>${label}</small><strong>${key === 'count' ? Number(data.totals?.[key] || 0).toLocaleString() : money(data.totals?.[key])}</strong></article>`).join('');
        this.renderPipelineSelect(data); this.renderPipelines(data, money); this.renderForecastFilters(data); this.renderForecasts(data, money); this.renderCurrencyControls(data); this.renderProducts(data, money); this.renderQuotes(data, money);
        this.element.querySelectorAll('[data-admin-only]').forEach(node => { node.hidden = !data.permissions?.configure; });
    }

    renderPipelineSelect(data) { const select = this.element.querySelector('[data-pipeline-select]'); if (!select) return; select.innerHTML = (data.pipelines || []).map(item => `<option value="${this.escape(item.id)}" ${item.id === this.selectedPipelineId ? 'selected' : ''}>${this.escape(item.name)}${item.isDefault == 1 ? ' (default)' : ''}</option>`).join(''); }

    renderPipelines(data, money) {
        const host = this.element.querySelector('[data-pipeline-board]'); if (!host) return;
        const stages = (data.stages || []).filter(item => item.pipelineId === this.selectedPipelineId);
        const summary = new Map((data.stageSummary || []).filter(item => item.pipelineId === this.selectedPipelineId).map(item => [item.stage, item]));
        host.innerHTML = stages.map((stage, index) => {
            const item = summary.get(stage.name) || {};
            const actions = data.permissions?.configure ? `<div class="nexa-stage-actions"><button class="btn btn-icon btn-sm" data-action="moveStage" data-direction="-1" data-stage-id="${this.escape(stage.id)}" ${index === 0 ? 'disabled' : ''} title="Move stage left"><span class="fas fa-arrow-left"></span></button><button class="btn btn-icon btn-sm" data-action="moveStage" data-direction="1" data-stage-id="${this.escape(stage.id)}" ${index === stages.length - 1 ? 'disabled' : ''} title="Move stage right"><span class="fas fa-arrow-right"></span></button><button class="btn btn-icon btn-sm" data-action="editStage" data-stage-id="${this.escape(stage.id)}" title="Configure ${this.escape(stage.name)}"><span class="fas fa-cog"></span></button></div>` : '';
            return `<article class="nexa-pipeline-stage" data-stage-card="${this.escape(stage.id)}"><header><span>${this.escape(stage.name)}</span><strong>${Number(item.count || 0)}</strong></header><div class="nexa-stage-value">${money(item.amount)}</div><div class="nexa-stage-probability"><span style="width:${stage.probability}%"></span></div><small>${stage.probability}% probability &middot; ${this.escape(stage.forecastCategory)}</small>${actions}${this.stageForm(stage)}</article>`;
        }).join('') || '<p class="nexa-empty-copy">No active stages are configured.</p>';
    }

    stageForm(stage) {
        if (!this.workspaceData?.permissions?.configure) return '';
        const fields = {name: 'Name', accountId: 'Account', amount: 'Amount', closeDate: 'Close date', nextStep: 'Next step', assignedUserId: 'Owner', lossReason: 'Loss reason'};
        return `<form class="nexa-stage-form" data-stage-form data-stage-id="${this.escape(stage.id)}" hidden><label>Name<input class="form-control" name="name" maxlength="160" value="${this.escape(stage.name)}" required></label><label>Probability<input class="form-control" name="probability" type="number" min="0" max="100" value="${stage.probability}" required></label><label>Forecast<select class="form-control" name="forecastCategory">${this.forecastOptions(stage.forecastCategory)}</select></label><div class="nexa-stage-flags"><label><input type="checkbox" name="isClosed" ${stage.isClosed == 1 ? 'checked' : ''}> Closed stage</label><label><input type="checkbox" name="isWon" ${stage.isWon == 1 ? 'checked' : ''}> Won stage</label></div><fieldset><legend>Required before entering</legend>${Object.entries(fields).map(([value, label]) => `<label><input type="checkbox" name="requiredFields" value="${value}" ${(stage.requiredFields || []).includes(value) ? 'checked' : ''}> ${label}</label>`).join('')}</fieldset><footer><button class="btn btn-primary btn-sm" type="submit">Save stage</button><button class="btn btn-danger btn-sm" type="button" data-action="archiveStage" data-stage-id="${this.escape(stage.id)}">Archive</button></footer></form>`;
    }

    toggleStage(event) { const form = event.currentTarget.closest('[data-stage-card]')?.querySelector('[data-stage-form]'); if (form) { form.hidden = !form.hidden; if (!form.hidden) form.querySelector('input')?.focus(); } }
    stagePayload(form) { return {name: form.elements.name.value, probability: form.elements.probability.value, forecastCategory: form.elements.forecastCategory.value, isClosed: form.elements.isClosed.checked, isWon: form.elements.isWon.checked, requiredFields: [...form.querySelectorAll('[name="requiredFields"]:checked')].map(input => input.value)}; }
    async updateStage(event) { event.preventDefault(); const form = event.currentTarget; this.disableForm(form, true); try { await Espo.Ajax.postRequest(`Nexa/sales/stages/${encodeURIComponent(form.dataset.stageId)}`, this.stagePayload(form)); Espo.Ui.success('Stage updated.'); await this.loadData(); } catch (error) { Espo.Ui.error(error?.message || 'Stage could not be updated.'); } finally { this.disableForm(form, false); } }
    async createStage(event) { event.preventDefault(); const form = event.currentTarget; this.disableForm(form, true); try { await Espo.Ajax.postRequest(`Nexa/sales/pipelines/${encodeURIComponent(this.selectedPipelineId)}/stages`, this.stagePayload(form)); form.reset(); Espo.Ui.success('Stage created.'); await this.loadData(); } catch (error) { Espo.Ui.error(error?.message || 'Stage could not be created.'); } finally { this.disableForm(form, false); } }
    async archiveStage(event) { if (!confirm('Archive this stage? Opportunities must be moved out first.')) return; try { await Espo.Ajax.deleteRequest(`Nexa/sales/stages/${encodeURIComponent(event.currentTarget.dataset.stageId)}`); Espo.Ui.success('Stage archived.'); await this.loadData(); } catch (error) { Espo.Ui.error(error?.message || 'Stage could not be archived.'); } }
    async moveStage(event) { const ids = (this.workspaceData.stages || []).filter(item => item.pipelineId === this.selectedPipelineId).map(item => item.id); this.moveId(ids, event.currentTarget.dataset.stageId, Number(event.currentTarget.dataset.direction)); try { await Espo.Ajax.postRequest(`Nexa/sales/pipelines/${encodeURIComponent(this.selectedPipelineId)}/stages/order`, {ids}); await this.loadData(); } catch (error) { Espo.Ui.error(error?.message || 'Stage order could not be saved.'); } }

    renderForecastFilters(data) { const host = this.element.querySelector('[data-forecast-filters]'); if (host) host.innerHTML = `${this.filterSelect('pipelineId', 'All pipelines', data.pipelines)}${this.filterSelect('ownerId', 'All owners', data.owners)}${this.filterSelect('teamId', 'All teams', data.teams)}`; }
    filterSelect(name, empty, items) { return `<label><span>${empty.replace('All ', '')}</span><select class="form-control" name="${name}" data-forecast-filter><option value="">${empty}</option>${(items || []).map(item => `<option value="${this.escape(item.id)}" ${this.filters[name] === item.id ? 'selected' : ''}>${this.escape(item.name)}</option>`).join('')}</select></label>`; }
    renderForecasts(data, money) { const host = this.element.querySelector('[data-forecast-list]'); if (host) host.innerHTML = (data.forecastSummary || []).map(item => `<article><div><span class="nexa-forecast-dot nexa-forecast-dot--${this.slug(item.category)}"></span><strong>${this.escape(item.category)}</strong><small>${Number(item.count || 0)} opportunities</small></div><b>${money(item.amount)}</b></article>`).join('') || '<p class="nexa-empty-copy">No opportunities match these forecast filters.</p>'; }

    renderProducts(data, money) {
        const host = this.element.querySelector('[data-product-list]'); if (!host) return;
        host.innerHTML = (data.products || []).map(item => `<article data-product-id="${this.escape(item.id)}"><span class="fas fa-box"></span><div><strong>${this.escape(item.name)}</strong><small>${this.escape(item.sku)} &middot; ${this.escape(item.unit || 'each')} &middot; ${Number(item.taxPercent || 0)}% tax</small></div><b>${money(item.unitPrice, item.currency)}</b>${data.permissions?.configure ? `<div class="nexa-product-actions"><button class="btn btn-icon" data-action="editProduct" data-product-id="${this.escape(item.id)}" title="Edit product"><span class="fas fa-pen"></span></button><button class="btn btn-icon text-danger" data-action="archiveProduct" data-product-id="${this.escape(item.id)}" title="Archive product"><span class="fas fa-archive"></span></button></div>` : ''}</article>`).join('') || '<p class="nexa-empty-copy">Your product library is empty.</p>';
    }
    renderQuotes(data, money) { const host = this.element.querySelector('[data-quote-list]'); if (host) host.innerHTML = (data.recentQuotes || []).map(item => `<article><span class="fas fa-file-invoice-dollar"></span><div><strong>${this.escape(item.name)}</strong><small>${this.escape(item.quoteNumber)} &middot; ${this.escape(item.status)} &middot; <a href="#Opportunity/view/${this.escape(item.opportunityId)}">${this.escape(item.opportunityName)}</a></small></div><b>${money(item.grandTotal, item.currency)}</b></article>`).join('') || '<p class="nexa-empty-copy">Quotes created from opportunities will appear here.</p>'; }
    renderCurrencyControls(data) { const select = this.element.querySelector('[data-product-currency]'); if (!select) return; const settings = data.currencySettings || {}; select.innerHTML = (settings.enabledCurrencies || ['USD']).map(code => `<option value="${this.escape(code)}">${this.escape(code)}</option>`).join(''); if (!select.value) select.value = settings.defaultCurrency || 'USD'; }

    async createPipeline(event) { event.preventDefault(); const form = event.currentTarget, input = form.elements.name; if (!input.value.trim()) return; this.disableForm(form, true); try { const result = await Espo.Ajax.postRequest('Nexa/sales/pipelines', {name: input.value.trim()}); this.selectedPipelineId = result.id; form.reset(); Espo.Ui.success('Pipeline created.'); await this.loadData(); } catch (error) { Espo.Ui.error(error?.message || 'Pipeline could not be created.'); } finally { this.disableForm(form, false); } }
    async editPipeline() { const pipeline = (this.workspaceData.pipelines || []).find(item => item.id === this.selectedPipelineId); if (!pipeline) return; const name = prompt('Pipeline name', pipeline.name); if (!name?.trim()) return; try { await Espo.Ajax.postRequest(`Nexa/sales/pipelines/${encodeURIComponent(pipeline.id)}`, {name: name.trim(), description: pipeline.description || '', isDefault: pipeline.isDefault == 1}); Espo.Ui.success('Pipeline updated.'); await this.loadData(); } catch (error) { Espo.Ui.error(error?.message || 'Pipeline could not be updated.'); } }
    async archivePipeline() { if (!confirm('Archive this pipeline? Opportunities must be moved first.')) return; try { await Espo.Ajax.deleteRequest(`Nexa/sales/pipelines/${encodeURIComponent(this.selectedPipelineId)}`); this.selectedPipelineId = null; Espo.Ui.success('Pipeline archived.'); await this.loadData(); } catch (error) { Espo.Ui.error(error?.message || 'Pipeline could not be archived.'); } }
    async movePipeline(event) { const ids = (this.workspaceData.pipelines || []).map(item => item.id); this.moveId(ids, this.selectedPipelineId, Number(event.currentTarget.dataset.direction)); try { await Espo.Ajax.postRequest('Nexa/sales/pipelines/order', {ids}); await this.loadData(); } catch (error) { Espo.Ui.error(error?.message || 'Pipeline order could not be saved.'); } }

    editProduct(event) { const product = (this.workspaceData.products || []).find(item => item.id === event.currentTarget.dataset.productId), form = this.element.querySelector('[data-product-form]'); if (!product || !form) return; Object.entries({id: product.id, name: product.name, sku: product.sku, description: product.description || '', unit: product.unit || 'each', unitPrice: product.unitPrice, currency: product.currency, taxPercent: product.taxPercent || 0}).forEach(([name, value]) => { if (form.elements[name]) form.elements[name].value = value; }); form.querySelector('[data-product-form-title]').textContent = 'Edit product'; form.elements.name.focus(); }
    cancelProductEdit() { const form = this.element.querySelector('[data-product-form]'); form.reset(); form.elements.id.value = ''; form.elements.currency.value = this.workspaceData?.currencySettings?.defaultCurrency || 'USD'; form.querySelector('[data-product-form-title]').textContent = 'Add a product'; }
    async saveProduct(event) { event.preventDefault(); const form = event.currentTarget, body = Object.fromEntries(new FormData(form).entries()), id = body.id; delete body.id; this.disableForm(form, true); try { await Espo.Ajax.postRequest(id ? `Nexa/sales/products/${encodeURIComponent(id)}` : 'Nexa/sales/products', body); this.cancelProductEdit(); Espo.Ui.success(id ? 'Product updated.' : 'Product created.'); await this.loadData(); } catch (error) { Espo.Ui.error(error?.message || 'Product could not be saved.'); } finally { this.disableForm(form, false); } }
    async archiveProduct(event) { if (!confirm('Archive this product? Existing line items and quotes will keep their snapshots.')) return; try { await Espo.Ajax.deleteRequest(`Nexa/sales/products/${encodeURIComponent(event.currentTarget.dataset.productId)}`); Espo.Ui.success('Product archived.'); await this.loadData(); } catch (error) { Espo.Ui.error(error?.message || 'Product could not be archived.'); } }

    moveId(ids, id, direction) { const index = ids.indexOf(id), target = index + direction; if (index < 0 || target < 0 || target >= ids.length) return; [ids[index], ids[target]] = [ids[target], ids[index]]; }
    forecastOptions(selected = 'Pipeline') { return ['Pipeline', 'Best Case', 'Commit', 'Closed', 'Omitted'].map(value => `<option ${value === selected ? 'selected' : ''}>${value}</option>`).join(''); }
    disableForm(form, disabled) { form.querySelectorAll('input, select, textarea, button').forEach(node => { node.disabled = disabled; }); }
    moneyFormatter(defaultCurrency = 'USD') { return (value, currency = defaultCurrency) => { try { return new Intl.NumberFormat(undefined, {style: 'currency', currency: currency || defaultCurrency, maximumFractionDigits: 2}).format(Number(value || 0)); } catch { return `${currency || defaultCurrency} ${Number(value || 0).toLocaleString()}`; } }; }
    slug(value) { return String(value || '').toLowerCase().replace(/[^a-z0-9]+/g, '-'); }
    escape(value) { const node = document.createElement('span'); node.textContent = String(value ?? ''); return node.innerHTML; }
});
