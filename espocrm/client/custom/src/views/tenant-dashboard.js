define('custom:views/tenant-dashboard', [
    'views/dashboard',
    'custom:product-surface-registry',
], (DashboardView, productSurfaceRegistry) => class extends DashboardView {
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

        const activeNames = new Set((this.dashboardLayout || []).map(item => item.name));
        const plannedDashboardWorkspaces = productSurfaceRegistry.dashboards
            .filter(item => !item.active && !activeNames.has(item.name));

        return {...data, tenant, firstName, plannedDashboardWorkspaces, isAdmin: this.getUser().isAdmin()};
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

        if (this.getUser().isAdmin()) {
            this.creditRequestsStatus = 'pending';
            this.element.querySelectorAll('[data-credit-requests-tab]').forEach(tab => {
                tab.addEventListener('click', () => {
                    this.creditRequestsStatus = tab.dataset.creditRequestsTab;
                    this.element.querySelectorAll('[data-credit-requests-tab]').forEach(item => {
                        const active = item === tab;
                        item.classList.toggle('active', active);
                        item.setAttribute('aria-selected', String(active));
                    });
                    this.loadCreditRequests();
                });
            });
            this.loadCreditRequests();
            this.loadCallSettings();
            this.element.querySelector('[data-call-settings-save]')?.addEventListener('click', () => this.saveCallSettings());
        }
    }

    async loadCallSettings() {
        const input = this.element.querySelector('[data-call-settings-per-call-cap]');
        if (!input) return;

        try {
            const payload = await Espo.Ajax.getRequest('Nexa/call/minutes');
            input.value = payload.perCallCapMinutes || 60;
        } catch (error) {
            // Non-blocking - the settings row just stays at its blank default.
        }
    }

    async saveCallSettings() {
        const input = this.element.querySelector('[data-call-settings-per-call-cap]');
        const button = this.element.querySelector('[data-call-settings-save]');
        const perCallCapMinutes = parseInt(input.value, 10);

        if (!perCallCapMinutes || perCallCapMinutes < 1 || perCallCapMinutes > 480) {
            Espo.Ui.error('Enter a per-call time limit between 1 and 480 minutes.');
            return;
        }

        button.disabled = true;
        try {
            await Espo.Ajax.postRequest('Nexa/call/settings', {perCallCapMinutes});
            Espo.Ui.success('Calling settings saved');
        } catch (error) {
            Espo.Ui.error('Could not save calling settings.');
        } finally {
            button.disabled = false;
        }
    }

    async loadCreditRequests() {
        const list = this.element.querySelector('[data-credit-requests-list]');
        if (!list) return;

        const status = this.creditRequestsStatus === 'history' ? 'all' : 'pending';
        list.innerHTML = '<li class="nexa-credit-requests-loading"><span class="fas fa-circle-notch fa-spin" aria-hidden="true"></span> Loading requests&hellip;</li>';

        try {
            const payload = await Espo.Ajax.getRequest('Nexa/call/credit-requests', {status});
            const items = (payload.list || []).filter(item => this.creditRequestsStatus === 'pending' ? item.status === 'pending' : item.status !== 'pending');
            this.renderCreditRequests(items);
        } catch (error) {
            list.innerHTML = '<li class="nexa-credit-requests-empty">Requests could not be loaded.</li>';
        }
    }

    renderCreditRequests(items) {
        const list = this.element.querySelector('[data-credit-requests-list]');
        if (!list) return;

        if (!items.length) {
            list.innerHTML = `<li class="nexa-credit-requests-empty">No ${this.creditRequestsStatus === 'history' ? 'reviewed' : 'pending'} requests.</li>`;
            return;
        }

        const formatDate = value => value
            ? new Intl.DateTimeFormat(undefined, {dateStyle: 'medium', timeStyle: 'short'}).format(new Date(`${value.replace(' ', 'T')}Z`))
            : '';

        list.innerHTML = items.map(item => {
            const requesterName = [item.requester_first_name, item.requester_last_name].filter(Boolean).join(' ') || 'A user';
            if (this.creditRequestsStatus === 'pending') {
                return `<li class="nexa-credit-request-row" data-credit-request-id="${item.id}">
                    <div class="nexa-credit-request-main">
                        <strong>${this.escapeHtml(requesterName)} requested ${item.requested_minutes} minutes</strong>
                        <p>${this.escapeHtml(item.reason)}</p>
                        <time>${formatDate(item.created_at)}</time>
                    </div>
                    <div class="nexa-credit-request-actions">
                        <label class="sr-only" for="grant-${item.id}">Minutes to grant</label>
                        <input id="grant-${item.id}" type="number" class="form-control" min="1" max="500" value="${item.requested_minutes}" data-credit-request-grant>
                        <button type="button" class="btn btn-primary btn-sm" data-credit-request-approve>Approve</button>
                        <button type="button" class="btn btn-default btn-sm" data-credit-request-deny>Deny</button>
                    </div>
                </li>`;
            }

            const reviewerName = [item.reviewer_first_name, item.reviewer_last_name].filter(Boolean).join(' ');
            const statusLabel = item.status === 'approved' ? 'Approved' : 'Denied';
            return `<li class="nexa-credit-request-row is-decided is-${item.status}" data-credit-request-id="${item.id}">
                <div class="nexa-credit-request-main">
                    <strong>${this.escapeHtml(requesterName)} requested ${item.requested_minutes} minutes</strong>
                    <p>${this.escapeHtml(item.reason)}</p>
                    <time>${formatDate(item.created_at)}</time>
                </div>
                <div class="nexa-credit-request-decision">
                    <span class="nexa-credit-request-status">${statusLabel}${item.status === 'approved' ? ` (${item.granted_minutes} min)` : ''}</span>
                    ${reviewerName ? `<span>by ${this.escapeHtml(reviewerName)}</span>` : ''}
                    ${item.decision_note ? `<p>${this.escapeHtml(item.decision_note)}</p>` : ''}
                </div>
            </li>`;
        }).join('');

        list.querySelectorAll('[data-credit-request-approve]').forEach(button => {
            button.addEventListener('click', () => {
                const row = button.closest('[data-credit-request-id]');
                const grantedMinutes = parseInt(row.querySelector('[data-credit-request-grant]').value, 10);
                this.decideCreditRequest(row.dataset.creditRequestId, true, grantedMinutes, null, row);
            });
        });
        list.querySelectorAll('[data-credit-request-deny]').forEach(button => {
            button.addEventListener('click', () => {
                const row = button.closest('[data-credit-request-id]');
                const note = window.prompt('Optional note for the requester (why it was denied):') || null;
                this.decideCreditRequest(row.dataset.creditRequestId, false, null, note, row);
            });
        });
    }

    async decideCreditRequest(requestId, approve, grantedMinutes, decisionNote, row) {
        row.querySelectorAll('button').forEach(button => button.disabled = true);
        try {
            await Espo.Ajax.postRequest('Nexa/call/credit-request/decide', {
                requestId, approve, grantedMinutes, decisionNote,
            });
            Espo.Ui.success(approve ? 'Request approved' : 'Request denied');
            this.loadCreditRequests();
        } catch (error) {
            Espo.Ui.error('Could not update this request. It may have already been reviewed.');
            this.loadCreditRequests();
        }
    }

    escapeHtml(value) {
        const node = document.createElement('span');
        node.textContent = String(value ?? '');
        return node.innerHTML;
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
