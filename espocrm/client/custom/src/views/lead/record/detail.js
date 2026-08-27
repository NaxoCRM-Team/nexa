define('custom:views/lead/record/detail', ['crm:views/lead/record/detail', 'custom:helpers/custom-properties'], (Dep, CustomProperties) => class extends Dep {
    setup() {
        super.setup();
        this.nexaCustomProperties = new CustomProperties(this, 'Lead', 'detail');
        this.listenTo(this.model, 'sync change', () => this.refreshWorkspace());
        this.once('remove', () => document.body.classList.remove('nexa-lead-record-page'));
    }

    afterRender() {
        const result = super.afterRender();
        document.body.classList.add('nexa-lead-record-page');
        this.prepareWorkspace();
        return result;
    }

    async prepareWorkspace() {
        if (this.workspacePending) return;
        const root = this.element;
        root?.classList.add('nexa-lead-record', 'nexa-lead-workspace-loading');
        root?.setAttribute('aria-busy', 'true');
        if (root?.querySelector('[data-nexa-lead-workspace]')) {
            this.finishWorkspaceLoading();
            return;
        }

        this.workspacePending = true;
        try {
            await this.model.fetch();
        } catch (error) {
            // The native record remains available when an optional refresh fails.
        } finally {
            this.workspacePending = false;
        }

        if (!this.isRendered()) return;
        this.renderWorkspace();
        this.finishWorkspaceLoading();
    }

    finishWorkspaceLoading() {
        this.element?.classList.remove('nexa-lead-workspace-loading');
        this.element?.removeAttribute('aria-busy');
    }

    renderWorkspace() {
        const root = this.element;
        const nativeRecord = root?.querySelector(':scope > .detail') || root?.querySelector('.detail');
        if (!root || !nativeRecord || root.querySelector('[data-nexa-lead-workspace]')) return;

        const shell = this.buildShell();
        nativeRecord.prepend(shell);
        this.placeNativeViews(nativeRecord, shell);
        this.bindTabs(shell);
        this.bindAssociationActions(shell, nativeRecord);
        this.refreshWorkspace();
        this.nexaCustomProperties.mount(shell.querySelector('[data-nexa-lead-custom-properties]'));
    }

    buildShell() {
        const shell = document.createElement('section');
        shell.className = 'nexa-lead-workspace';
        shell.dataset.nexaLeadWorkspace = 'true';
        shell.setAttribute('aria-label', 'Lead qualification workspace');
        shell.innerHTML = `
            <header class="nexa-lead-toolbar">
                <div class="nexa-lead-identity">
                    <a href="#Lead" class="nexa-lead-back" aria-label="Back to Leads"><span class="fas fa-arrow-left" aria-hidden="true"></span></a>
                    <span class="nexa-lead-avatar" data-nexa-lead-avatar aria-hidden="true"></span>
                    <div class="nexa-lead-heading"><p>Lead qualification</p><h2 data-nexa-lead-name></h2><span data-nexa-lead-subtitle></span></div>
                </div>
                <div class="nexa-lead-native-actions" data-nexa-lead-actions></div>
            </header>
            <div class="nexa-lead-grid">
                <aside class="nexa-lead-profile" aria-label="Lead profile">
                    <section class="nexa-lead-profile-section">
                        <div class="nexa-lead-section-heading"><p>Prospect</p><h3>Contact information</h3></div>
                        <dl class="nexa-lead-facts">
                            ${this.fact('Email', 'emailAddress')}${this.fact('Phone', 'phoneNumber')}
                            ${this.fact('Job title', 'title')}${this.fact('Company', 'accountName')}
                            ${this.fact('Website', 'website')}${this.fact('Address', 'address')}
                            ${this.fact('Lead owner', 'assignedUser')}${this.fact('Teams', 'teams')}
                            ${this.fact('Created', 'createdAt')}
                        </dl>
                    </section>
                    <section class="nexa-lead-profile-section">
                        <div class="nexa-lead-section-heading"><p>Qualification</p><h3>Sales readiness</h3></div>
                        <dl class="nexa-lead-facts">
                            ${this.fact('Status', 'status')}${this.fact('Rating', 'rating')}${this.fact('Source', 'source')}
                            ${this.fact('Lifecycle stage', 'lifecycleStage')}${this.fact('Lead score', 'leadScore')}
                        </dl>
                    </section>
                </aside>
                <main class="nexa-lead-main">
                    <section class="nexa-lead-metrics" aria-label="Lead qualification summary">
                        ${this.metric('leadScore', 'Lead score', 'fas fa-chart-line')}
                        ${this.metric('rating', 'Rating', 'fas fa-temperature-high')}
                        ${this.metric('nextActivityAt', 'Next activity', 'far fa-calendar-check')}
                    </section>
                    <nav class="nexa-lead-tabs" role="tablist" aria-label="Lead workspace">
                        ${this.tab('overview', 'Overview', true)}${this.tab('activity', 'Activity')}
                        ${this.tab('history', 'History')}${this.tab('tasks', 'Tasks')}
                    </nav>
                    <section class="nexa-lead-panel is-active" role="tabpanel" data-nexa-lead-panel="overview">
                        <div class="nexa-lead-panel-heading"><div><p>Qualification overview</p><h3>Engagement and conversion readiness</h3></div></div>
                        <div class="nexa-lead-engagement-grid">
                            ${this.signal('First activity', 'firstActivityAt', 'far fa-flag')}
                            ${this.signal('Last activity', 'lastActivityAt', 'fas fa-history')}
                            ${this.signal('Last email interaction', 'lastEmailInteractionAt', 'far fa-envelope')}
                            ${this.signal('Last website visit', 'lastWebsiteVisitAt', 'fas fa-globe')}
                        </div>
                        <section class="nexa-lead-description" data-nexa-lead-description hidden><h4>Qualification notes</h4><p></p></section>
                        <section class="nexa-lead-custom-properties" data-nexa-lead-custom-properties></section>
                    </section>
                    <section class="nexa-lead-panel" role="tabpanel" data-nexa-lead-panel="activity" hidden><div data-nexa-lead-native-panel="activities"></div></section>
                    <section class="nexa-lead-panel" role="tabpanel" data-nexa-lead-panel="history" hidden><div data-nexa-lead-native-panel="history"></div></section>
                    <section class="nexa-lead-panel" role="tabpanel" data-nexa-lead-panel="tasks" hidden><div data-nexa-lead-native-panel="tasks"></div></section>
                </main>
                <aside class="nexa-lead-context" aria-label="Marketing and relationship context">
                    <section class="nexa-lead-context-card">
                        <div class="nexa-lead-section-heading"><p>Marketing</p><h3>Communication eligibility</h3></div>
                        <div class="nexa-lead-marketing-status" data-nexa-lead-marketing-status></div>
                        <dl class="nexa-lead-facts">${this.fact('Legal basis', 'legalBasis')}${this.fact('Campaign', 'campaign')}</dl>
                    </section>
                    <section class="nexa-lead-context-card">
                        <div class="nexa-lead-section-heading"><p>Lead relationships</p><h3>Connected records</h3></div>
                        <p class="nexa-lead-context-copy">Records directly connected to this Lead.</p>
                        <div class="nexa-lead-association-actions">
                            <button type="button" class="btn btn-default" data-nexa-lead-set-campaign><span class="fas fa-bullhorn" aria-hidden="true"></span><span>Set campaign</span></button>
                            <button type="button" class="btn btn-link nexa-lead-remove-campaign" data-nexa-lead-remove-campaign hidden><span class="fas fa-unlink" aria-hidden="true"></span><span>Remove campaign</span></button>
                            <button type="button" class="btn btn-default" data-nexa-lead-add-target-list><span class="fas fa-plus" aria-hidden="true"></span><span>Add to target list</span></button>
                        </div>
                        <div class="nexa-lead-association-list" data-nexa-lead-associations aria-live="polite">
                            <div class="nexa-lead-association-empty"><span class="fas fa-circle-notch fa-spin" aria-hidden="true"></span><span>Loading relationships...</span></div>
                        </div>
                    </section>
                    <section class="nexa-lead-context-card nexa-lead-conversion-card">
                        <span class="fas fa-random" aria-hidden="true"></span>
                        <div><strong>Ready to qualify?</strong><p>Use the Convert action above to create or connect a Contact, Account and Opportunity while preserving this Lead history.</p></div>
                    </section>
                </aside>
            </div>`;
        return shell;
    }

    fact(label, field) {
        return `<div><dt>${label}</dt><dd data-nexa-lead-field="${field}"></dd></div>`;
    }

    metric(field, label, icon) {
        return `<article class="nexa-lead-metric"><span class="${icon}" aria-hidden="true"></span><div><strong data-nexa-lead-metric="${field}">--</strong><small>${label}</small></div></article>`;
    }

    signal(label, field, icon) {
        return `<article class="nexa-lead-signal"><span class="${icon}" aria-hidden="true"></span><div><small>${label}</small><strong data-nexa-lead-signal="${field}">Not recorded</strong></div></article>`;
    }

    tab(name, label, selected = false) {
        return `<button type="button" role="tab" data-nexa-lead-tab="${name}" aria-selected="${String(selected)}">${label}</button>`;
    }

    /** Move the real Espo controls and panels so Convert, ACL and relationship behavior stay authoritative. */
    placeNativeViews(nativeRecord, shell) {
        const grid = nativeRecord.querySelector(':scope > .record-grid');
        const actionHost = shell.querySelector('[data-nexa-lead-actions]');

        const actionNodes = [
            ...nativeRecord.querySelectorAll(':scope > .record-buttons, :scope > .edit-buttons'),
            ...this.element.querySelectorAll(':scope > .record-buttons, :scope > .edit-buttons'),
        ];
        [...new Set(actionNodes)].forEach(node => actionHost.append(node));
        ['activities', 'history', 'tasks'].forEach(name => {
            const panel = grid?.querySelector(`.side [data-name="${name}"]`) || nativeRecord.querySelector(`[data-name="${name}"]`);
            const host = shell.querySelector(`[data-nexa-lead-native-panel="${name}"]`);
            if (panel && host) host.append(panel);
        });
        // Native relationship panels remain concealed in the grid. The sidebar renders
        // one clean summary instead of detaching their nested buttons and list controls.
        grid?.classList.add('nexa-lead-native-grid-host');
    }

    bindAssociationActions(shell, nativeRecord) {
        const canEdit = this.getAcl().checkModel(this.model, 'edit');
        const campaignButton = shell.querySelector('[data-nexa-lead-set-campaign]');
        const removeCampaignButton = shell.querySelector('[data-nexa-lead-remove-campaign]');
        const targetListButton = shell.querySelector('[data-nexa-lead-add-target-list]');
        [campaignButton, removeCampaignButton, targetListButton].forEach(button => {
            button.disabled = !canEdit;
            if (!canEdit) button.title = 'You do not have permission to edit this Lead.';
        });
        campaignButton?.addEventListener('click', () => {
            if (!canEdit) return;
            this.openCampaignPicker();
        });
        removeCampaignButton?.addEventListener('click', () => this.confirmCampaignRemoval());
        targetListButton?.addEventListener('click', () => {
            if (!canEdit) return;
            const panel = nativeRecord.querySelector('.bottom [data-name="targetLists"], .panel[data-name="targetLists"]');
            const control = panel?.querySelector('[data-action="selectRelated"]');
            if (!control) {
                Espo.Ui.error('The Target List picker is unavailable. Refresh the Lead and try again.');
                return;
            }
            control.click();
        });
    }

    openCampaignPicker() {
        this.createView('campaignSelector', 'views/modals/select-records', {
            scope: 'Campaign',
            multiple: false,
            createButton: true,
            headerText: this.value('campaignId') ? 'Change campaign' : 'Set campaign',
        }, view => {
            view.render();
            this.listenToOnce(view, 'select', model => this.saveCampaign(model));
        });
    }

    async saveCampaign(campaign) {
        if (!campaign?.id) return;
        Espo.Ui.notify('Connecting campaign...');
        try {
            await this.model.save({campaignId: campaign.id, campaignName: campaign.get('name')}, {patch: true});
            await this.model.fetch();
            this.refreshWorkspace();
            Espo.Ui.success('Campaign connected.');
        } catch (error) {
            Espo.Ui.notify(false);
            Espo.Ui.error(error?.message || 'The Campaign could not be connected.');
        }
    }

    confirmCampaignRemoval() {
        if (!this.value('campaignId') || !this.getAcl().checkModel(this.model, 'edit')) return;
        this.confirm({
            message: `Remove ${this.value('campaignName') || 'this Campaign'} from this Lead?`,
            confirmText: 'Remove campaign',
        }, () => this.removeCampaign());
    }

    async removeCampaign() {
        Espo.Ui.notify('Removing campaign...');
        try {
            await this.model.save({campaignId: null, campaignName: null}, {patch: true});
            await this.model.fetch();
            this.refreshWorkspace();
            Espo.Ui.success('Campaign removed.');
        } catch (error) {
            Espo.Ui.notify(false);
            Espo.Ui.error(error?.message || 'The Campaign could not be removed.');
        }
    }

    bindTabs(shell) {
        const tabs = [...shell.querySelectorAll('[data-nexa-lead-tab]')];
        tabs.forEach((button, index) => {
            button.addEventListener('click', () => this.activateTab(shell, button.dataset.nexaLeadTab));
            button.addEventListener('keydown', event => {
                if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
                event.preventDefault();
                const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 :
                    (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
                tabs[next].focus();
                tabs[next].click();
            });
        });
    }

    activateTab(shell, name) {
        shell.querySelectorAll('[data-nexa-lead-tab]').forEach(tab => tab.setAttribute('aria-selected', String(tab.dataset.nexaLeadTab === name)));
        shell.querySelectorAll('[data-nexa-lead-panel]').forEach(panel => {
            const active = panel.dataset.nexaLeadPanel === name;
            panel.hidden = !active;
            panel.classList.toggle('is-active', active);
        });
    }

    refreshWorkspace() {
        const shell = this.element?.querySelector('[data-nexa-lead-workspace]');
        if (!shell) return;
        const name = this.value('name') || 'Unnamed Lead';
        const subtitle = [this.value('title'), this.value('accountName')].filter(Boolean).join(' at ') || this.value('emailAddress') || 'Qualification record';
        shell.querySelector('[data-nexa-lead-name]').textContent = name;
        shell.querySelector('[data-nexa-lead-subtitle]').textContent = subtitle;
        shell.querySelector('[data-nexa-lead-avatar]').textContent = this.initials(name);
        const campaignButton = shell.querySelector('[data-nexa-lead-set-campaign] span:last-child');
        const removeCampaignButton = shell.querySelector('[data-nexa-lead-remove-campaign]');
        if (campaignButton) campaignButton.textContent = this.value('campaignId') ? 'Change campaign' : 'Set campaign';
        if (removeCampaignButton) removeCampaignButton.hidden = !this.value('campaignId');

        const fields = {
            emailAddress: this.linkValue('emailAddress', 'mailto'), phoneNumber: this.linkValue('phoneNumber', 'tel'),
            title: this.value('title'), accountName: this.value('accountName'), website: this.linkValue('website'),
            address: this.formatAddress(), assignedUser: this.value('assignedUserName'), teams: this.listValue('teamsNames'),
            createdAt: this.formatDateTime(this.value('createdAt')), status: this.badge('status'), rating: this.badge('rating'),
            source: this.value('source'), lifecycleStage: this.badge('lifecycleStage'), leadScore: this.value('leadScore', true),
            legalBasis: this.humanize(this.value('legalBasis')), campaign: this.value('campaignName'),
        };
        Object.entries(fields).forEach(([field, value]) => {
            const target = shell.querySelector(`[data-nexa-lead-field="${field}"]`);
            if (target) target.innerHTML = value || '<span class="nexa-lead-empty">Not recorded</span>';
        });

        const metrics = {leadScore: this.value('leadScore', true) || '0', rating: this.value('rating') || 'Unrated', nextActivityAt: this.formatDateTime(this.value('nextActivityAt')) || 'None scheduled'};
        Object.entries(metrics).forEach(([field, value]) => {
            const target = shell.querySelector(`[data-nexa-lead-metric="${field}"]`);
            if (target) target.textContent = value;
        });
        ['firstActivityAt', 'lastActivityAt', 'lastEmailInteractionAt', 'lastWebsiteVisitAt'].forEach(field => {
            const target = shell.querySelector(`[data-nexa-lead-signal="${field}"]`);
            if (target) target.textContent = this.formatDateTime(this.value(field)) || 'Not recorded';
        });

        const marketing = this.value('marketingStatus') || 'Non-Marketing';
        const marketingStatus = shell.querySelector('[data-nexa-lead-marketing-status]');
        marketingStatus.className = `nexa-lead-marketing-status nexa-lead-marketing-status--${this.slug(marketing)}`;
        marketingStatus.innerHTML = `<span class="fas ${marketing === 'Unsubscribed' ? 'fa-ban' : marketing === 'Marketing' ? 'fa-check-circle' : 'fa-minus-circle'}" aria-hidden="true"></span><div><strong>${this.escape(marketing)}</strong><small>${marketing === 'Marketing' ? 'Eligible for configured marketing activity' : marketing === 'Unsubscribed' ? 'Marketing communication is restricted' : 'Not currently counted as a marketing Lead'}</small></div>`;

        const description = shell.querySelector('[data-nexa-lead-description]');
        const descriptionText = this.value('description');
        description.hidden = !descriptionText;
        description.querySelector('p').textContent = descriptionText || '';
        this.loadAssociations(shell);
    }

    async loadAssociations(shell) {
        if (this.associationsPending || !this.model.id) return;
        this.associationsPending = true;
        let targetLists = [];
        try {
            const response = await Espo.Ajax.getRequest(`Lead/${encodeURIComponent(this.model.id)}/targetLists`, {maxSize: 5});
            targetLists = Array.isArray(response?.list) ? response.list : [];
        } catch (error) {
            // Converted records and Campaign still render if Target Lists are unavailable.
        } finally {
            this.associationsPending = false;
        }

        if (!this.isRendered() || shell !== this.element?.querySelector('[data-nexa-lead-workspace]')) return;
        const convertedRecords = [
            this.relationship('Contact', this.value('createdContactId'), this.value('createdContactName'), 'fas fa-user'),
            this.relationship('Account', this.value('createdAccountId'), this.value('createdAccountName'), 'fas fa-building'),
            this.relationship('Opportunity', this.value('createdOpportunityId'), this.value('createdOpportunityName'), 'fas fa-chart-line'),
        ].filter(Boolean);
        const qualificationRecords = [
            this.relationship('Campaign', this.value('campaignId'), this.value('campaignName'), 'fas fa-bullhorn'),
            ...targetLists.map(record => this.relationship('TargetList', record.id, record.name, 'fas fa-list', 'Target list')),
        ].filter(Boolean);
        const host = shell.querySelector('[data-nexa-lead-associations]');
        if (!host) return;
        host.innerHTML = `
            ${this.relationshipGroup('Campaign and lists', qualificationRecords,
                'No campaign or target list is connected yet. Use the buttons above to add one.')}
            ${this.relationshipGroup('Conversion results', convertedRecords,
                'No converted records yet. Use Convert when this Lead is qualified.')}`;
    }

    relationshipGroup(label, records, emptyMessage) {
        return `<section class="nexa-lead-association-group"><h4>${this.escape(label)}</h4>
            ${records.length ? records.join('') : `<div class="nexa-lead-association-empty"><span class="fas fa-link" aria-hidden="true"></span><span>${this.escape(emptyMessage)}</span></div>`}
        </section>`;
    }

    relationship(scope, id, name, icon, label = scope) {
        if (!id || !name) return '';
        return `<a class="nexa-lead-association" href="#${scope}/view/${encodeURIComponent(id)}">
            <span class="nexa-lead-association-icon ${icon}" aria-hidden="true"></span>
            <span><small>${this.escape(label)}</small><strong>${this.escape(name)}</strong></span>
            <span class="fas fa-chevron-right" aria-hidden="true"></span>
        </a>`;
    }

    value(field, preserveZero = false) {
        const value = this.model.get(field);
        return preserveZero && value === 0 ? '0' : value || '';
    }

    listValue(field) {
        const value = this.model.get(field);
        return Array.isArray(value) ? value.join(', ') : value || '';
    }

    badge(field) {
        const value = this.value(field);
        return value ? `<span class="nexa-lead-value-badge nexa-lead-value-badge--${this.slug(value)}">${this.escape(value)}</span>` : '';
    }

    linkValue(field, scheme = '') {
        const value = this.value(field);
        if (!value) return '';
        let href = value;
        if (scheme) href = `${scheme}:${value}`;
        else if (!/^https?:\/\//i.test(href)) href = `https://${href}`;
        return `<a href="${this.escape(href)}" ${scheme ? '' : 'target="_blank" rel="noopener"'}>${this.escape(value.replace(/^https?:\/\//i, ''))}</a>`;
    }

    formatAddress() {
        return ['addressStreet', 'addressCity', 'addressState', 'addressPostalCode', 'addressCountry'].map(field => this.value(field)).filter(Boolean).join(', ');
    }

    formatDateTime(value) {
        if (!value) return '';
        const normalized = String(value).replace(' ', 'T');
        const date = new Date(normalized + (/[zZ]|[+-]\d\d:\d\d$/.test(normalized) ? '' : 'Z'));
        return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat(undefined, {year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit'}).format(date);
    }

    initials(name) {
        return String(name).split(/\s+/).filter(Boolean).slice(0, 2).map(part => part.charAt(0).toUpperCase()).join('') || '?';
    }

    humanize(value) {
        return value ? String(value).replace(/([a-z])([A-Z])/g, '$1 $2') : '';
    }

    slug(value) {
        return String(value).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
    }

    escape(value) {
        const node = document.createElement('span');
        node.textContent = String(value ?? '');
        return node.innerHTML;
    }
});
