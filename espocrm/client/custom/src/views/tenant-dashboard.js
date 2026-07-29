define('custom:views/tenant-dashboard', ['views/dashboard'], DashboardView => class extends DashboardView {
    template = 'custom:tenant-dashboard';

    data() {
        const data = super.data();
        const tenant = this.getHelper().getAppParam('nexaTenant') || {};
        const firstName = this.getUser().get('firstName') || this.getUser().get('userName') || 'there';

        return {...data, tenant, firstName};
    }

    afterRender() {
        super.afterRender();
        this.summaryElement = this.element.querySelector('[data-dashboard-summary]');
        this.rangeElement = this.element.querySelector('[data-dashboard-range]');
        const tenant = this.getHelper().getAppParam('nexaTenant') || {};
        const storageKey = `nexaDashboardRange:${tenant.id || 'workspace'}`;
        const storedRange = this.getStorage().get('state', storageKey);

        if (['7d', '30d', '90d', 'all'].includes(storedRange)) {
            this.rangeElement.value = storedRange;
        }
        this.rangeElement?.addEventListener('change', () => {
            this.getStorage().set('state', storageKey, this.rangeElement.value);
            this.loadSummary();
        });
        this.element.querySelector('[data-action="refreshDashboard"]')?.addEventListener('click', () => this.loadSummary());
        this.element.querySelector('[data-action="retryDashboard"]')?.addEventListener('click', () => this.loadSummary());
        this.loadSummary();
    }

    async loadSummary() {
        this.setSummaryState('loading');

        try {
            const data = await Espo.Ajax.getRequest('Nexa/dashboard/summary', {range: this.rangeElement?.value || '30d'});
            this.renderSummary(data);
            this.setSummaryState(data.empty ? 'empty' : 'ready');
        } catch (error) {
            this.setSummaryState(error?.status === 403 ? 'denied' : 'error');
        }
    }

    setSummaryState(state) {
        if (!this.summaryElement) return;
        this.summaryElement.dataset.state = state;
        this.summaryElement.querySelectorAll('[data-dashboard-state]').forEach(element => {
            element.hidden = element.dataset.dashboardState !== state;
        });
        this.summaryElement.setAttribute('aria-busy', String(state === 'loading'));
    }

    renderSummary(data) {
        const formatNumber = value => new Intl.NumberFormat().format(value || 0);
        const formatCurrency = value => new Intl.NumberFormat(undefined, {
            style: 'currency', currency: data.currency || 'GBP', maximumFractionDigits: 0,
        }).format(value || 0);
        const metrics = {
            accounts: formatNumber,
            contacts: formatNumber,
            leads: formatNumber,
            openOpportunities: formatNumber,
            pipelineValue: formatCurrency,
            openTasks: formatNumber,
        };

        Object.entries(metrics).forEach(([name, formatter]) => {
            const metric = data.metrics?.[name];
            const element = this.element.querySelector(`[data-metric="${name}"]`);
            const card = element?.closest('.nexa-metric');
            if (!element || !metric) return;
            element.textContent = metric.available ? formatter(metric.value) : 'Restricted';
            card?.classList.toggle('is-restricted', !metric.available);
        });

        this.renderPipeline(data.pipeline || [], formatCurrency);
        this.renderActivities(data.activities || []);
        const updated = this.element.querySelector('[data-dashboard-updated]');
        if (updated) updated.textContent = `Updated ${new Intl.DateTimeFormat(undefined, {hour: '2-digit', minute: '2-digit'}).format(new Date(data.generatedAt))}`;
    }

    renderPipeline(items, formatCurrency) {
        const container = this.element.querySelector('[data-pipeline-list]');
        if (!container) return;
        container.replaceChildren();
        const maximum = Math.max(...items.map(item => item.amount), 1);

        items.forEach(item => {
            const row = document.createElement('li');
            const label = document.createElement('div');
            const bar = document.createElement('span');
            row.className = 'nexa-pipeline-row';
            label.innerHTML = `<span></span><strong></strong>`;
            label.children[0].textContent = `${item.stage} (${item.count})`;
            label.children[1].textContent = formatCurrency(item.amount);
            bar.className = 'nexa-pipeline-bar';
            bar.style.setProperty('--nexa-pipeline-width', `${Math.max(4, Math.round(item.amount / maximum * 100))}%`);
            row.append(label, bar);
            container.append(row);
        });
    }

    renderActivities(items) {
        const container = this.element.querySelector('[data-activity-list]');
        if (!container) return;
        container.replaceChildren();

        items.forEach(item => {
            const row = document.createElement('li');
            const link = document.createElement('a');
            const date = document.createElement('time');
            link.href = `#${item.entityType}/view/${item.id}`;
            link.textContent = item.name;
            date.dateTime = item.dateStart;
            date.textContent = new Intl.DateTimeFormat(undefined, {weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit'}).format(new Date(`${item.dateStart}Z`));
            row.append(link, date);
            container.append(row);
        });
    }
});
