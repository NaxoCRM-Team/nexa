define('custom:views/tenant-dashboard', ['views/dashboard'], DashboardView => class extends DashboardView {
    template = 'custom:tenant-dashboard';

    setupCurrentTabLayout() {
        const savedLayout = this.getPreferences().get('dashboardLayout');

        if (!this.dashboardLayout && this.isLegacyDashboardLayout(savedLayout)) {
            this.dashboardLayout = this.buildDefaultDashboardLayout();
            this.shouldPersistDashboardLayout = true;
        } else if (!this.dashboardLayout && this.isNexaDashboardLayoutV1(savedLayout)) {
            this.dashboardLayout = this.migrateNexaDashboardLayout(savedLayout);
            this.shouldPersistDashboardLayout = true;
        }

        super.setupCurrentTabLayout();
    }

    isLegacyDashboardLayout(layout) {
        if (!Array.isArray(layout) || layout.length !== 1) return false;

        const items = layout[0]?.layout || [];
        const names = items.map(item => item.name).sort();

        return names.length === 2 && names[0] === 'Activities' && names[1] === 'Stream';
    }

    isNexaDashboardLayoutV1(layout) {
        if (!Array.isArray(layout)) return false;

        const expected = {
            'nexa-overview': [
                ['nexa-activities', 0, 0, 2, 4],
                ['nexa-sales-pipeline', 2, 0, 1, 2],
                ['nexa-opportunities-stage', 3, 0, 1, 4],
                ['nexa-sales-month', 2, 2, 1, 2],
                ['nexa-stream', 0, 4, 2, 4],
                ['nexa-tasks', 2, 4, 2, 4],
            ],
            'nexa-sales': [
                ['nexa-sales-leads', 0, 0, 2, 4],
                ['nexa-sales-opportunities', 2, 0, 2, 4],
                ['nexa-sales-funnel', 0, 4, 2, 3],
                ['nexa-sales-stage', 2, 4, 1, 3],
                ['nexa-sales-trend', 3, 4, 1, 3],
            ],
            'nexa-schedule': [
                ['nexa-calendar', 0, 0, 2, 5],
                ['nexa-schedule-activities', 2, 0, 2, 4],
                ['nexa-schedule-tasks', 0, 5, 2, 4],
                ['nexa-calls', 2, 4, 1, 4],
                ['nexa-meetings', 3, 4, 1, 4],
            ],
        };

        return Object.entries(expected).every(([tabId, geometry]) => {
            const tab = layout.find(item => item.id === tabId);

            if (!tab || tab.layout?.length !== geometry.length) return false;

            return geometry.every(([id, x, y, width, height]) => {
                const item = tab.layout.find(candidate => candidate.id === id);

                return item && item.x === x && item.y === y &&
                    item.width === width && item.height === height;
            });
        });
    }

    migrateNexaDashboardLayout(layout) {
        const defaults = this.buildDefaultDashboardLayout();
        const defaultTabIds = new Set(defaults.map(tab => tab.id));

        // Preserve team-created workspaces while replacing only the unchanged Nexa defaults.
        return defaults.concat(layout.filter(tab => !defaultTabIds.has(tab.id)));
    }

    buildDefaultDashboardLayout() {
        const tabs = [
            {
                id: 'nexa-overview',
                name: 'Overview',
                layout: [
                    {id: 'nexa-activities', name: 'Activities', x: 0, y: 0, width: 2, height: 2},
                    {id: 'nexa-sales-pipeline', name: 'SalesPipeline', x: 2, y: 0, width: 2, height: 2},
                    {id: 'nexa-opportunities-stage', name: 'OpportunitiesByStage', x: 0, y: 2, width: 2, height: 2},
                    {id: 'nexa-sales-month', name: 'SalesByMonth', x: 2, y: 2, width: 2, height: 2},
                    {id: 'nexa-stream', name: 'Stream', x: 0, y: 4, width: 2, height: 2},
                    {id: 'nexa-tasks', name: 'Tasks', x: 2, y: 4, width: 2, height: 2},
                ],
            },
            {
                id: 'nexa-sales',
                name: 'Sales',
                layout: [
                    {id: 'nexa-sales-leads', name: 'Leads', x: 0, y: 0, width: 2, height: 2},
                    {id: 'nexa-sales-opportunities', name: 'Opportunities', x: 2, y: 0, width: 2, height: 2},
                    {id: 'nexa-sales-funnel', name: 'SalesPipeline', x: 0, y: 2, width: 2, height: 2},
                    {id: 'nexa-sales-stage', name: 'OpportunitiesByStage', x: 2, y: 2, width: 2, height: 2},
                    {id: 'nexa-sales-trend', name: 'SalesByMonth', x: 0, y: 4, width: 4, height: 2},
                ],
            },
            {
                id: 'nexa-schedule',
                name: 'Schedule',
                layout: [
                    {id: 'nexa-calendar', name: 'Calendar', x: 0, y: 0, width: 2, height: 3},
                    {id: 'nexa-schedule-activities', name: 'Activities', x: 2, y: 0, width: 2, height: 3},
                    {id: 'nexa-schedule-tasks', name: 'Tasks', x: 0, y: 3, width: 2, height: 2},
                    {id: 'nexa-calls', name: 'Calls', x: 2, y: 3, width: 2, height: 2},
                    {id: 'nexa-meetings', name: 'Meetings', x: 0, y: 5, width: 4, height: 2},
                ],
            },
        ];

        // Core dashlets remain responsible for record ACL; filtering here avoids unusable empty widgets.
        return tabs.map(tab => ({
            ...tab,
            layout: tab.layout.filter(item => this.isDashletAvailable(item.name)),
        }));
    }

    isDashletAvailable(name) {
        const definitions = this.getMetadata().get(['dashlets', name]);

        if (!definitions) return false;
        if (definitions.aclScope && !this.getAcl().check(definitions.aclScope)) return false;
        if (definitions.accessDataList &&
            !Espo.Utils.checkAccessDataList(definitions.accessDataList, this.getAcl(), this.getUser())) {
            return false;
        }

        return true;
    }

    data() {
        const data = super.data();
        const tenant = this.getHelper().getAppParam('nexaTenant') || {};
        const firstName = this.getUser().get('firstName') || this.getUser().get('userName') || 'there';

        return {...data, tenant, firstName};
    }

    afterRender() {
        this.element.classList.add('nexa-dashboard');
        this.element.setAttribute('aria-labelledby', 'nexa-dashboard-title');
        super.afterRender();

        if (this.shouldPersistDashboardLayout) {
            this.shouldPersistDashboardLayout = false;
            this.saveLayout();
        }

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
