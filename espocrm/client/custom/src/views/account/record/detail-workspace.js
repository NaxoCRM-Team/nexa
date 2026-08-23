define('custom:views/account/record/detail-workspace', ['views/record/detail'], Dep => class extends Dep {
    setup() {
        super.setup();
        document.body.classList.add('nexa-account-detail-page');
        this.listenTo(this.model, 'sync change', () => this.refreshCompanySummary());
        this.once('remove', () => {
            document.body.classList.remove('nexa-account-detail-page');
            this.companyWorkspaceObserver?.disconnect();
            this.companyAssociationResizeObserver?.disconnect();
            this.accountEngagementDeleteDialog?.remove();
        });
    }

    afterRender() {
        const result = super.afterRender();
        this.prepareCompanyWorkspace();
        return result;
    }

    async prepareCompanyWorkspace() {
        if (this.companyWorkspacePending) return;

        const root = this.element;
        root?.classList.add('nexa-account-detail-workspace', 'nexa-company-workspace-loading');
        if (root?.querySelector('[data-nexa-company-workspace]')) {
            root.classList.remove('nexa-company-workspace-loading');
            return;
        }

        this.companyWorkspacePending = true;
        try {
            await this.model.fetch();
        } catch (error) {
            // Keep the native record available when an optional refresh fails.
        } finally {
            this.companyWorkspacePending = false;
        }

        if (!this.isRendered()) return;
        this.renderCompanyWorkspace();
        root?.classList.remove('nexa-company-workspace-loading');
    }

    renderCompanyWorkspace() {
        const root = this.element;
        const nativeRecord = root?.querySelector(':scope > .detail') || root?.querySelector('.detail');
        if (!nativeRecord || nativeRecord.querySelector('[data-nexa-company-workspace]')) return;

        const shell = document.createElement('section');
        shell.className = 'nexa-company-record';
        shell.dataset.nexaCompanyWorkspace = 'true';
        shell.innerHTML = `
            <header class="nexa-company-toolbar">
                <div class="nexa-company-toolbar-identity">
                    <a href="#Account" class="nexa-company-back" aria-label="Back to accounts"><span class="fas fa-arrow-left" aria-hidden="true"></span></a>
                    <span class="nexa-company-avatar" aria-hidden="true"></span>
                    <div><p>Company record</p><h2 data-nexa-company-name></h2><span data-nexa-company-subtitle></span></div>
                </div>
                <div class="nexa-company-native-actions" data-nexa-company-actions></div>
            </header>
            <div class="nexa-company-grid">
                <aside class="nexa-company-profile" aria-label="Company information">
                    <div class="nexa-company-section-heading"><p>Account profile</p><h3>Company information</h3></div>
                    <dl class="nexa-company-facts">
                        <div><dt>Website</dt><dd data-nexa-company-field="website"></dd></div>
                        <div><dt>Email</dt><dd data-nexa-company-field="emailAddress"></dd></div>
                        <div><dt>Phone</dt><dd data-nexa-company-field="phoneNumber"></dd></div>
                        <div><dt>Type</dt><dd data-nexa-company-field="type"></dd></div>
                        <div><dt>Industry</dt><dd data-nexa-company-field="industry"></dd></div>
                        <div><dt>Annual revenue</dt><dd data-nexa-company-field="annualRevenue"></dd></div>
                        <div><dt>Employees</dt><dd data-nexa-company-field="numberOfEmployees"></dd></div>
                        <div><dt>Lead score</dt><dd data-nexa-company-field="leadScore"></dd></div>
                        <div><dt>Parent company</dt><dd data-nexa-company-field="parentAccount"></dd></div>
                        <div><dt>Account owner</dt><dd data-nexa-company-field="assignedUser"></dd></div>
                        <div><dt>Teams</dt><dd data-nexa-company-field="teams"></dd></div>
                        <div><dt>Tags</dt><dd data-nexa-company-field="tags"></dd></div>
                        <div><dt>Location</dt><dd data-nexa-company-field="location"></dd></div>
                        <div><dt>Created</dt><dd data-nexa-company-field="createdAt"></dd></div>
                        <div><dt>Modified</dt><dd data-nexa-company-field="modifiedAt"></dd></div>
                    </dl>
                    <section class="nexa-company-profile-copy" data-nexa-company-description hidden>
                        <h4>About this company</h4><p></p>
                    </section>
                    <section class="nexa-company-custom-properties" data-nexa-company-custom-properties hidden>
                        <h4>Additional properties</h4><dl></dl>
                    </section>
                </aside>
                <main class="nexa-company-main">
                    <section class="nexa-company-metrics" aria-label="Account summary">
                        ${this.metricCard('contacts', 'Contacts', 'fa-users')}
                        ${this.metricCard('opportunities', 'Sales opportunities', 'fa-chart-line')}
                        ${this.metricCard('cases', 'Service cases', 'fa-headset')}
                    </section>
                    <section class="nexa-company-edit-host" data-nexa-company-edit-host aria-label="Edit company information">
                        <div class="nexa-company-panel-heading"><div><p>Editing account</p><h3>Company properties</h3></div><span>Save or cancel using the toolbar above</span></div>
                        <div data-nexa-company-edit-fields></div>
                    </section>
                    <nav class="nexa-company-tabs" role="tablist" aria-label="Company workspace">
                        ${this.engagementTab('activity', 'Activity', true)}
                        ${this.engagementTab('notes', 'Notes')}
                        ${this.engagementTab('tasks', 'Tasks')}
                        ${this.engagementTab('meetings', 'Meetings')}
                        ${this.engagementTab('calls', 'Calls')}
                        ${this.engagementTab('emails', 'Email')}
                    </nav>
                    <section class="nexa-company-tab-panel is-active" role="tabpanel" data-nexa-company-panel="activity">
                        <div class="nexa-company-panel-heading"><div><p>Timeline</p><h3>Company activity</h3></div></div>
                        ${this.engagementPanel('activity', 'Search all activity')}
                    </section>
                    ${this.engagementPanelSection('notes', 'Notes across the company')}
                    ${this.engagementPanelSection('tasks', 'Tasks across the company')}
                    ${this.engagementPanelSection('meetings', 'Meetings across the company')}
                    ${this.engagementPanelSection('calls', 'Calls across the company')}
                    ${this.engagementPanelSection('emails', 'Email across the company')}
                </main>
                <aside class="nexa-company-associations" aria-label="Company relationships">
                    <section class="nexa-company-contact-rail" aria-labelledby="nexa-company-contacts-heading">
                        <header class="nexa-company-contact-rail-heading">
                            <div><p>Connected records</p><h3 id="nexa-company-contacts-heading">Contacts <span data-nexa-company-contact-count>(--)</span></h3></div>
                            <a class="nexa-company-contact-add" data-nexa-company-contact-add href="#"><span class="fas fa-plus" aria-hidden="true"></span><span>Add</span></a>
                        </header>
                        <div class="nexa-company-contact-cards" data-nexa-company-contact-cards aria-live="polite">
                            <div class="nexa-company-contact-empty"><span class="fas fa-circle-notch fa-spin" aria-hidden="true"></span><span>Loading contacts...</span></div>
                        </div>
                        <a class="nexa-company-contact-view-all" data-nexa-company-contact-view-all href="#"><span>View all associated contacts</span><span class="fas fa-external-link-alt" aria-hidden="true"></span></a>
                    </section>
                    <div class="nexa-company-section-heading nexa-company-relationship-heading"><p>More connected records</p><h3>Relationships</h3></div>
                    <div class="nexa-company-relationship-summary" data-nexa-company-relationship-summary aria-live="polite"></div>
                </aside>
            </div>`;

        nativeRecord.prepend(shell);
        this.placeNativeAccountViews(nativeRecord, shell);
        this.installAssociationScrollControls(shell);
        this.bindCompanyTabs(shell);
        this.bindContactRail(shell);
        this.refreshCompanySummary();
        this.loadCompanyMetrics(shell);
        this.loadRelationshipOverview(shell);
        this.loadAccountEngagement(shell);
    }

    engagementTab(key, label, selected = false) {
        return `<button type="button" role="tab" aria-selected="${selected}" data-nexa-company-tab="${key}">
            <span>${label}</span>
        </button>`;
    }

    engagementPanelSection(key, heading) {
        return `<section class="nexa-company-tab-panel" role="tabpanel" data-nexa-company-panel="${key}" hidden>
            <div class="nexa-company-panel-heading"><div><p>Account engagement</p><h3>${heading}</h3></div></div>
            ${this.engagementPanel(key, `Search ${key}`)}
        </section>`;
    }

    engagementPanel(key, placeholder) {
        return `<div class="nexa-company-engagement" data-nexa-engagement="${key}">
            <div class="nexa-company-engagement-toolbar">
                <label class="nexa-company-engagement-search">
                    <span class="fas fa-search" aria-hidden="true"></span>
                    <span class="sr-only">${placeholder}</span>
                    <input type="search" class="form-control" placeholder="${placeholder}" data-nexa-engagement-search="${key}" autocomplete="off">
                </label>
                <button type="button" class="btn btn-default nexa-company-filter-toggle" data-nexa-engagement-filter-toggle="${key}" aria-expanded="false"><span class="fas fa-sliders-h" aria-hidden="true"></span><span>Filters</span></button>
            </div>
            <div class="nexa-company-engagement-filters" data-nexa-engagement-filters="${key}" hidden>
                <label><span>Date</span><select class="form-control" data-nexa-engagement-period="${key}">${this.engagementPeriodOptions()}</select></label>
                <label><span>Activity assigned to</span><select class="form-control" data-nexa-engagement-owner="${key}"><option value="all">All owners</option><option value="me">Me</option></select></label>
                ${key === 'activity' || key === 'notes' ? '' : `<label><span>Status</span><select class="form-control" data-nexa-engagement-status="${key}"><option value="all">All statuses</option></select></label>`}
            </div>
            <div class="nexa-company-engagement-list" data-nexa-engagement-list="${key}" aria-live="polite">
                <div class="nexa-company-engagement-empty"><span class="fas fa-circle-notch fa-spin" aria-hidden="true"></span><span>Loading company activity...</span></div>
            </div>
            <button type="button" class="btn btn-default nexa-company-engagement-more" data-nexa-engagement-more="${key}" hidden>Load more</button>
        </div>`;
    }

    engagementPeriodOptions() {
        return [
            ['all', 'All time'], ['today', 'Today'], ['yesterday', 'Yesterday'],
            ['thisWeek', 'This week'], ['last7', 'Last 7 days'], ['thisMonth', 'This month'],
            ['last30', 'Last 30 days'], ['last90', 'Last 90 days'], ['thisYear', 'This year'],
        ].map(([value, label]) => `<option value="${value}">${label}</option>`).join('');
    }

    metricCard(key, label, icon) {
        return `<a class="nexa-company-metric" data-nexa-company-metric="${key}" href="#" aria-label="${label}">
            <span class="fas ${icon}" aria-hidden="true"></span>
            <div><strong>--</strong><small>${label}</small></div>
        </a>`;
    }

    placeNativeAccountViews(nativeRecord, shell) {
        const grid = nativeRecord.querySelector(':scope > .record-grid');
        const actions = shell.querySelector('[data-nexa-company-actions]');
        const editFields = shell.querySelector('[data-nexa-company-edit-fields]');

        nativeRecord.querySelectorAll(':scope > .record-buttons, :scope > .edit-buttons')
            .forEach(node => actions.append(node));
        if (grid) {
            const middle = grid.querySelector('.middle');
            const extra = grid.querySelector('.extra');
            if (middle) editFields.append(middle);
            if (extra) editFields.append(extra);
            // Native Account relationship panels stay inside the concealed grid.
            // The Nexa relationship rail below is the single visible association surface.
            grid.classList.add('nexa-company-native-grid-host');
        }
    }

    bindCompanyTabs(shell) {
        shell.querySelectorAll('[data-nexa-company-tab]').forEach(button => {
            button.addEventListener('click', () => {
                const selected = button.dataset.nexaCompanyTab;
                shell.querySelectorAll('[data-nexa-company-tab]').forEach(tab =>
                    tab.setAttribute('aria-selected', String(tab === button)));
                shell.querySelectorAll('[data-nexa-company-panel]').forEach(panel => {
                    const active = panel.dataset.nexaCompanyPanel === selected;
                    panel.hidden = !active;
                    panel.classList.toggle('is-active', active);
                });
            });
            button.addEventListener('keydown', event => {
                if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
                const tabs = [...shell.querySelectorAll('[data-nexa-company-tab]')];
                const current = tabs.indexOf(button);
                const target = event.key === 'Home' ? tabs[0] : event.key === 'End' ? tabs.at(-1) :
                    tabs[(current + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length];
                event.preventDefault();
                target.focus();
                target.click();
            });
        });

        shell.querySelectorAll('[data-nexa-engagement-search]').forEach(input => {
            input.addEventListener('input', () => {
                const key = input.dataset.nexaEngagementSearch;
                this.accountEngagementSearch = this.accountEngagementSearch || {};
                this.accountEngagementSearch[key] = input.value.trim().toLowerCase();
                this.renderEngagementList(shell, key);
            });
        });

        this.accountEngagementFilters = this.accountEngagementFilters || {};
        shell.querySelectorAll('[data-nexa-engagement-filter-toggle]').forEach(button => {
            button.addEventListener('click', () => {
                const filters = shell.querySelector(`[data-nexa-engagement-filters="${button.dataset.nexaEngagementFilterToggle}"]`);
                filters.hidden = !filters.hidden;
                button.setAttribute('aria-expanded', String(!filters.hidden));
            });
        });
        shell.querySelectorAll('[data-nexa-engagement-period], [data-nexa-engagement-owner], [data-nexa-engagement-status]').forEach(select => {
            select.addEventListener('change', () => {
                const key = select.dataset.nexaEngagementPeriod || select.dataset.nexaEngagementOwner || select.dataset.nexaEngagementStatus;
                this.accountEngagementFilters[key] = {
                    period: shell.querySelector(`[data-nexa-engagement-period="${key}"]`)?.value || 'all',
                    owner: shell.querySelector(`[data-nexa-engagement-owner="${key}"]`)?.value || 'all',
                    status: shell.querySelector(`[data-nexa-engagement-status="${key}"]`)?.value || 'all',
                };
                this.renderEngagementList(shell, key);
            });
        });

        shell.addEventListener('click', event => this.handleEngagementAction(event, shell));
        shell.querySelectorAll('[data-nexa-engagement-more]').forEach(button => {
            button.addEventListener('click', () => this.loadTimelinePage(shell, button.dataset.nexaEngagementMore, true));
        });
    }

    bindContactRail(shell) {
        const accountId = encodeURIComponent(this.model.id);
        const accountName = encodeURIComponent(this.model.get('name') || '');
        const listUrl = `#Contact/account?id=${accountId}&name=${accountName}`;
        shell.querySelector('[data-nexa-company-contact-view-all]').href = listUrl;
        shell.querySelector('[data-nexa-company-contact-add]').href = `#Contact/create?accountId=${accountId}&accountName=${accountName}`;

    }

    /**
     * Chrome can suppress a native scrollbar for the nested relationship rail.
     * Keep a dedicated scroll viewport and synchronize it with an explicit,
     * keyboard-operable scrollbar so the control is always discoverable.
     */
    installAssociationScrollControls(shell) {
        const column = shell.querySelector('.nexa-company-associations');
        if (!column || column.querySelector('[data-nexa-company-scrollbar]')) return;

        const viewport = document.createElement('div');
        viewport.className = 'nexa-company-associations-scroll';
        viewport.dataset.nexaCompanyAssociationsScroll = 'true';
        while (column.firstChild) viewport.append(column.firstChild);

        const controls = document.createElement('div');
        controls.className = 'nexa-company-scrollbar';
        controls.dataset.nexaCompanyScrollbar = 'true';
        controls.setAttribute('aria-label', 'Scroll company relationships');
        controls.innerHTML = `
            <button type="button" data-scroll-up aria-label="Scroll relationships up"><span class="fas fa-caret-up" aria-hidden="true"></span></button>
            <div class="nexa-company-scrollbar-track" data-scroll-track>
                <button type="button" class="nexa-company-scrollbar-thumb" data-scroll-thumb aria-label="Relationship scroll position"></button>
            </div>
            <button type="button" data-scroll-down aria-label="Scroll relationships down"><span class="fas fa-caret-down" aria-hidden="true"></span></button>`;
        column.append(viewport, controls);

        const track = controls.querySelector('[data-scroll-track]');
        const thumb = controls.querySelector('[data-scroll-thumb]');
        const update = () => {
            const available = track.clientHeight;
            const range = Math.max(0, viewport.scrollHeight - viewport.clientHeight);
            const thumbHeight = range ? Math.max(36, available * viewport.clientHeight / viewport.scrollHeight) : available;
            const travel = Math.max(0, available - thumbHeight);
            thumb.style.height = `${thumbHeight}px`;
            thumb.style.transform = `translateY(${range ? travel * viewport.scrollTop / range : 0}px)`;
            controls.classList.toggle('is-static', range === 0);
        };
        const scrollBy = amount => viewport.scrollBy({top: amount, behavior: 'smooth'});
        controls.querySelector('[data-scroll-up]').addEventListener('click', () => scrollBy(-120));
        controls.querySelector('[data-scroll-down]').addEventListener('click', () => scrollBy(120));
        viewport.addEventListener('scroll', update, {passive: true});
        track.addEventListener('click', event => {
            if (event.target === thumb) return;
            scrollBy(event.offsetY < thumb.offsetTop ? -viewport.clientHeight * .8 : viewport.clientHeight * .8);
        });
        thumb.addEventListener('pointerdown', event => {
            if (controls.classList.contains('is-static')) return;
            event.preventDefault();
            thumb.setPointerCapture(event.pointerId);
            const startY = event.clientY;
            const startTop = viewport.scrollTop;
            const available = Math.max(1, track.clientHeight - thumb.offsetHeight);
            const range = Math.max(0, viewport.scrollHeight - viewport.clientHeight);
            const move = moveEvent => viewport.scrollTop = startTop + (moveEvent.clientY - startY) * range / available;
            const stop = () => {
                thumb.removeEventListener('pointermove', move);
                thumb.removeEventListener('pointerup', stop);
                thumb.removeEventListener('pointercancel', stop);
            };
            thumb.addEventListener('pointermove', move);
            thumb.addEventListener('pointerup', stop);
            thumb.addEventListener('pointercancel', stop);
        });

        this.companyAssociationResizeObserver?.disconnect();
        this.companyAssociationResizeObserver = new ResizeObserver(update);
        this.companyAssociationResizeObserver.observe(viewport);
        [...viewport.children].forEach(child => this.companyAssociationResizeObserver.observe(child));
        window.setTimeout(update, 0);
    }

    refreshCompanySummary() {
        const shell = this.element?.querySelector('[data-nexa-company-workspace]');
        if (!shell) return;

        const name = this.model.get('name') || 'Unnamed account';
        const industry = this.model.get('industry') || 'Industry not recorded';
        const location = this.fullAddress();
        shell.querySelector('[data-nexa-company-name]').textContent = name;
        shell.querySelector('.nexa-company-avatar').textContent = name.trim().charAt(0).toUpperCase() || '?';
        shell.querySelector('[data-nexa-company-subtitle]').textContent = `${industry} - ${location}`;

        this.setFact(shell, 'website', this.linkValue(this.model.get('website')));
        this.setFact(shell, 'emailAddress', this.emailValue(this.model.get('emailAddress')));
        this.setFact(shell, 'phoneNumber', this.model.get('phoneNumber') || 'Not recorded');
        this.setFact(shell, 'type', this.model.get('type') || 'Not recorded');
        this.setFact(shell, 'industry', industry);
        this.setFact(shell, 'annualRevenue', this.formatAnnualRevenue());
        this.setFact(shell, 'numberOfEmployees', this.formatCount(this.model.get('numberOfEmployees')));
        this.setFact(shell, 'leadScore', this.formatLeadScore());
        this.setFact(shell, 'parentAccount', this.recordLinkValue('Account', this.model.get('parentAccountId'), this.model.get('parentAccountName')));
        this.setFact(shell, 'assignedUser', this.ownerValue());
        this.setFact(shell, 'teams', this.linkListValue('Team', this.model.get('teamsIds'), this.model.get('teamsNames')));
        this.setFact(shell, 'tags', this.tagsValue());
        this.setFact(shell, 'location', location);
        this.setFact(shell, 'createdAt', this.formatDate(this.model.get('createdAt')));
        this.setFact(shell, 'modifiedAt', this.modifiedAuditValue());
        this.renderCompanyDescription(shell);
        this.renderCustomProperties(shell);
    }

    setFact(shell, field, value) {
        const target = shell.querySelector(`[data-nexa-company-field="${field}"]`);
        if (!target) return;
        target.replaceChildren(value instanceof Node ? value : document.createTextNode(String(value)));
        const config = this.companyFactEditConfig(field);
        const aclFields = config?.fields || (config ? [config.field || field] : []);
        if (!config || !this.getAcl().checkModel(this.model, 'edit') ||
            aclFields.some(name => !this.getAcl().checkField('Account', name, 'edit'))) return;
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'nexa-company-fact-edit';
        button.setAttribute('aria-label', `Edit ${target.previousElementSibling?.textContent || field}`);
        button.innerHTML = '<span class="fas fa-pencil-alt" aria-hidden="true"></span>';
        button.addEventListener('click', event => {
            event.preventDefault();
            this.startCompanyFactEdit(shell, field, target, config);
        });
        target.append(button);
    }

    companyFactEditConfig(field) {
        return {
            website: {type: 'text'},
            emailAddress: {type: 'email'},
            phoneNumber: {type: 'tel'},
            type: {type: 'enum'},
            industry: {type: 'enum'},
            annualRevenue: {type: 'currency'},
            numberOfEmployees: {type: 'number'},
            parentAccount: {type: 'link', entityType: 'Account'},
            assignedUser: {type: 'link', entityType: 'User'},
            teams: {type: 'linkMultiple', entityType: 'Team'},
            tags: {type: 'tags'},
            location: {type: 'address', fields: ['billingAddressStreet', 'billingAddressCity', 'billingAddressState', 'billingAddressPostalCode', 'billingAddressCountry']},
        }[field] || null;
    }

    async startCompanyFactEdit(shell, field, target, config) {
        if (this.companyFactEditing) return;
        this.companyFactEditing = field;
        const editor = document.createElement('div');
        editor.className = `nexa-company-fact-editor is-${config.type}`;

        try {
            await this.populateCompanyFactEditor(editor, field, config);
        } catch (error) {
            this.companyFactEditing = null;
            Espo.Ui.error('This company field could not be opened.');
            return;
        }
        target.replaceChildren(editor);
        editor.setAttribute('aria-label', `${target.previousElementSibling?.textContent || field} inline editor. Press Enter to save or Escape to cancel.`);
        editor.querySelector('input, select')?.focus();

        let canceled = false;
        const cancel = () => {
            if (this.companyFactEditing !== field) return;
            canceled = true;
            this.companyFactEditing = null;
            this.refreshCompanySummary();
        };
        const save = async () => {
            if (canceled || this.companyFactEditing !== field || editor.dataset.saving === 'true') return;
            editor.dataset.saving = 'true';
            editor.setAttribute('aria-busy', 'true');
            editor.querySelectorAll('input, select').forEach(control => control.disabled = true);
            try {
                await this.model.save(this.readCompanyFactEditor(editor, field, config), {patch: true});
                this.companyFactEditing = null;
                this.refreshCompanySummary();
                Espo.Ui.success('Company information updated');
            } catch (error) {
                editor.dataset.saving = 'false';
                editor.removeAttribute('aria-busy');
                editor.querySelectorAll('input, select').forEach(control => control.disabled = false);
                editor.querySelector('input, select')?.focus();
                Espo.Ui.error('The company information could not be updated.');
            }
        };
        editor.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                event.preventDefault();
                cancel();
            }
            if (event.key === 'Enter') {
                event.preventDefault();
                save();
            }
        });
        editor.addEventListener('focusout', () => window.setTimeout(() => {
            if (!editor.contains(document.activeElement)) save();
        }, 0));
    }

    async populateCompanyFactEditor(editor, field, config) {
        if (config.type === 'address') {
            const labels = ['Street', 'City', 'State', 'Postal code', 'Country'];
            config.fields.forEach((name, index) => {
                const input = document.createElement('input');
                input.className = 'form-control input-sm';
                input.dataset.companyAddressField = name;
                input.value = this.model.get(name) || '';
                input.placeholder = labels[index];
                input.setAttribute('aria-label', labels[index]);
                editor.append(input);
            });
            return;
        }
        if (config.type === 'link' || config.type === 'linkMultiple') {
            const select = document.createElement('select');
            select.className = 'form-control input-sm';
            select.multiple = config.type === 'linkMultiple';
            select.dataset.companyFactInput = 'true';
            if (!select.multiple) select.append(new Option('Not assigned', ''));
            const payload = await Espo.Ajax.getRequest(config.entityType, {select: 'id,name', maxSize: 100, orderBy: 'name', order: 'asc'});
            const selectedIds = config.type === 'linkMultiple'
                ? new Set(this.model.get(`${field}Ids`) || [])
                : new Set([this.model.get(`${field}Id`) || '']);
            (payload?.list || []).filter(record => config.entityType !== 'Account' || record.id !== this.model.id).forEach(record => {
                const option = new Option(record.name || 'Unnamed record', record.id, false, selectedIds.has(record.id));
                option.dataset.name = record.name || '';
                select.append(option);
            });
            editor.append(select);
            return;
        }
        if (config.type === 'enum') {
            const select = document.createElement('select');
            select.className = 'form-control input-sm';
            select.dataset.companyFactInput = 'true';
            const options = this.getMetadata().get(['entityDefs', 'Account', 'fields', field, 'options']) || [];
            ['', ...options.filter(value => value !== '')].forEach(value => select.append(new Option(value || 'Not recorded', value, false, value === (this.model.get(field) || ''))));
            editor.append(select);
            return;
        }
        const input = document.createElement('input');
        input.className = 'form-control input-sm';
        input.dataset.companyFactInput = 'true';
        input.type = config.type === 'tags' ? 'text' : config.type === 'currency' ? 'number' : config.type;
        input.inputMode = ['number', 'currency'].includes(config.type) ? 'decimal' : '';
        input.value = config.type === 'tags' ? (this.model.get(field) || []).join(', ') : (this.model.get(field) ?? '');
        if (['number', 'currency'].includes(config.type)) input.min = '0';
        editor.append(input);
    }

    readCompanyFactEditor(editor, field, config) {
        if (config.type === 'address') {
            return Object.fromEntries([...editor.querySelectorAll('[data-company-address-field]')]
                .map(input => [input.dataset.companyAddressField, input.value.trim() || null]));
        }
        const input = editor.querySelector('[data-company-fact-input]');
        if (config.type === 'link') {
            const option = input.selectedOptions[0];
            return {[`${field}Id`]: option?.value || null, [`${field}Name`]: option?.value ? option.textContent : null};
        }
        if (config.type === 'linkMultiple') {
            const options = [...input.selectedOptions];
            return {[`${field}Ids`]: options.map(option => option.value), [`${field}Names`]: Object.fromEntries(options.map(option => [option.value, option.textContent]))};
        }
        if (config.type === 'tags') return {[field]: input.value.split(',').map(value => value.trim()).filter(Boolean).slice(0, 30)};
        if (config.type === 'currency') {
            const currencies = this.getConfig().get('currencyList') || [];
            const configured = this.model.get(`${field}Currency`) || this.getConfig().get('defaultCurrency');
            const currency = currencies.length
                ? (currencies.includes(configured) ? configured : currencies[0])
                : (configured || 'USD');

            return {
                [field]: input.value.trim() === '' ? null : Number(input.value.replace(/,/g, '')),
                [`${field}Currency`]: currency,
            };
        }
        if (config.type === 'number') return {[field]: input.value === '' ? null : Number(input.value)};
        return {[field]: input.value.trim() || null};
    }

    linkValue(value) {
        if (!value) return 'Not recorded';
        const href = /^https?:\/\//i.test(value) ? value : `https://${value}`;
        const link = document.createElement('a');
        link.href = href;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        link.textContent = String(value).replace(/^https?:\/\//i, '').replace(/\/$/, '');
        return link;
    }

    emailValue(value) {
        if (!value) return 'Not recorded';
        const link = document.createElement('a');
        link.href = `mailto:${value}`;
        link.textContent = value;
        return link;
    }

    recordLinkValue(entityType, id, name) {
        if (!id || !name) return 'Not recorded';
        const link = document.createElement('a');
        link.href = `#${entityType}/view/${encodeURIComponent(id)}`;
        link.textContent = name;
        return link;
    }

    linkListValue(entityType, ids, names) {
        const values = Array.isArray(ids) ? ids : [];
        if (!values.length) return 'Not assigned';
        const container = document.createElement('span');
        container.className = 'nexa-company-inline-links';
        values.forEach((id, index) => {
            const link = document.createElement('a');
            link.href = `#${entityType}/view/${encodeURIComponent(id)}`;
            link.textContent = names?.[id] || 'Unnamed team';
            container.append(link);
        });
        return container;
    }

    tagsValue() {
        const values = this.model.get('tags');
        if (!Array.isArray(values) || !values.length) return 'Not recorded';
        const container = document.createElement('span');
        container.className = 'nexa-company-tags';
        values.forEach(value => {
            const tag = document.createElement('span');
            tag.textContent = value;
            container.append(tag);
        });
        return container;
    }

    formatCount(value) {
        const number = Number(value);
        return Number.isFinite(number) ? new Intl.NumberFormat().format(Math.max(0, number)) : 'Not recorded';
    }

    renderCompanyDescription(shell) {
        const section = shell.querySelector('[data-nexa-company-description]');
        const value = String(this.model.get('description') || '').trim();
        section.hidden = !value;
        section.querySelector('p').textContent = value;
    }

    renderCustomProperties(shell) {
        const section = shell.querySelector('[data-nexa-company-custom-properties]');
        const list = section.querySelector('dl');
        const fields = this.getMetadata().get(['entityDefs', 'Account', 'fields']) || {};
        const entries = Object.entries(fields).filter(([name, defs]) => {
            if (!defs?.isCustom || defs.disabled || defs.readOnly || defs.notStorable) return false;
            if (this.getAcl().checkField('Account', name, 'read') === false) return false;
            const value = this.model.get(name);
            return value !== null && value !== undefined && value !== '' && (!Array.isArray(value) || value.length);
        });
        list.replaceChildren();
        entries.forEach(([name]) => {
            const row = document.createElement('div');
            const term = document.createElement('dt');
            const value = document.createElement('dd');
            term.textContent = this.translate(name, 'fields', 'Account');
            const raw = this.model.get(name);
            value.textContent = Array.isArray(raw) ? raw.join(', ') : String(raw);
            row.append(term, value);
            list.append(row);
        });
        section.hidden = entries.length === 0;
    }

    ownerValue() {
        const name = this.model.get('assignedUserName');
        const id = this.model.get('assignedUserId');
        if (!name || !id) return 'Not assigned';
        const link = document.createElement('a');
        link.href = `#User/view/${encodeURIComponent(id)}`;
        link.textContent = name;
        return link;
    }

    modifiedAuditValue() {
        const audit = document.createElement('span');
        audit.className = 'nexa-company-audit';

        const timestamp = document.createElement('span');
        timestamp.className = 'nexa-company-audit-time';
        timestamp.textContent = this.formatDateTime(this.model.get('modifiedAt'));
        audit.append(timestamp);

        const actor = document.createElement('span');
        actor.className = 'nexa-company-audit-user';
        actor.append(document.createTextNode('by '));

        const name = this.model.get('modifiedByName');
        const id = this.model.get('modifiedById');
        if (name && id) {
            const link = document.createElement('a');
            link.href = `#User/view/${encodeURIComponent(id)}`;
            link.textContent = name;
            actor.append(link);
        } else {
            actor.append(document.createTextNode(name || 'User not recorded'));
        }
        audit.append(actor);

        return audit;
    }

    fullAddress() {
        const parts = ['billingAddressStreet', 'billingAddressCity', 'billingAddressState',
            'billingAddressPostalCode', 'billingAddressCountry']
            .map(field => String(this.model.get(field) || '').trim()).filter(Boolean);
        return parts.join(', ') || 'Not recorded';
    }

    formatAnnualRevenue() {
        const value = Number(this.model.get('annualRevenue'));
        if (!Number.isFinite(value)) return 'Not recorded';
        const currency = this.model.get('annualRevenueCurrency') || this.getConfig().get('defaultCurrency') || 'USD';
        try {
            return new Intl.NumberFormat(undefined, {style: 'currency', currency, maximumFractionDigits: 0}).format(value);
        } catch (error) {
            return `${value.toLocaleString()} ${currency}`;
        }
    }

    formatLeadScore() {
        const value = Number(this.model.get('leadScore'));
        return Number.isFinite(value) ? String(Math.max(0, value)) : 'Not scored';
    }

    formatDate(value) {
        if (!value) return 'Not recorded';
        const date = new Date(String(value).replace(' ', 'T') + 'Z');
        return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat(undefined, {
            day: 'numeric', month: 'short', year: 'numeric',
        }).format(date);
    }

    formatDateTime(value) {
        if (!value) return 'Not recorded';
        const date = new Date(String(value).replace(' ', 'T') + 'Z');
        return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat(undefined, {
            day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit',
        }).format(date);
    }

    async loadCompanyMetrics(shell) {
        const id = encodeURIComponent(this.model.id);
        const links = {
            contacts: `#Contact/account?id=${id}&name=${encodeURIComponent(this.model.get('name') || '')}`,
            opportunities: `#Account/related/${id}/opportunities`,
            cases: `#Account/related/${id}/cases`,
        };

        await Promise.all(Object.keys(links).map(async key => {
            const card = shell.querySelector(`[data-nexa-company-metric="${key}"]`);
            card.href = links[key];
            try {
                const result = await Espo.Ajax.getRequest(`Account/${id}/${key}`, {maxSize: 1});
                card.querySelector('strong').textContent = Math.max(0, Number(result.total) || 0);
            } catch (error) {
                card.querySelector('strong').textContent = '--';
                card.title = 'This information is unavailable for your current permissions.';
            }
        }));
    }

    async loadRelationshipOverview(shell) {
        const host = shell.querySelector('[data-nexa-company-relationship-summary]');
        if (!host) return;
        host.innerHTML = '<div class="nexa-company-contact-empty"><span class="fas fa-circle-notch fa-spin" aria-hidden="true"></span><span>Loading relationships...</span></div>';

        const definitions = [
            {key: 'subsidiaries', label: 'Subsidiaries', entityType: 'Account', icon: 'fa-sitemap'},
            {key: 'opportunities', label: 'Opportunities', entityType: 'Opportunity', icon: 'fa-chart-line'},
            {key: 'cases', label: 'Cases', entityType: 'Case', icon: 'fa-headset'},
            {key: 'documents', label: 'Documents', entityType: 'Document', icon: 'fa-file-alt'},
        ];
        const cards = await Promise.all(definitions.map(async definition => {
            if (!this.getAcl().check(definition.entityType, 'read')) return null;
            try {
                const result = await Espo.Ajax.getRequest(`Account/${encodeURIComponent(this.model.id)}/${definition.key}`, {
                    select: 'id,name,createdAt', maxSize: 6, orderBy: 'createdAt', order: 'desc',
                });
                return {...definition, total: Math.max(0, Number(result?.total) || 0), list: result?.list || []};
            } catch (error) {
                return null;
            }
        }));

        host.replaceChildren();
        const parentId = this.model.get('parentAccountId');
        const parentName = this.model.get('parentAccountName');
        if (parentId && parentName) {
            host.append(this.relationshipCard({
                key: 'parentAccount', label: 'Parent company', entityType: 'Account', icon: 'fa-building',
                total: 1, list: [{id: parentId, name: parentName}],
            }));
        }
        cards.filter(Boolean).forEach(card => host.append(this.relationshipCard(card)));

        const campaignId = this.model.get('campaignId');
        const campaignName = this.model.get('campaignName');
        if (campaignId && campaignName && this.getAcl().check('Campaign', 'read')) {
            host.append(this.relationshipCard({
                key: 'campaign', label: 'Source campaign', entityType: 'Campaign', icon: 'fa-bullhorn',
                total: 1, list: [{id: campaignId, name: campaignName}],
            }));
        }
        if (!host.children.length) host.append(this.contactRailEmpty('No additional relationships are visible yet.', 'fa-link'));
    }

    relationshipCard(definition) {
        const card = document.createElement('section');
        card.className = 'nexa-company-relationship-card';
        const urls = this.relationshipUrls(definition);
        card.innerHTML = `<header>
            <button type="button" class="nexa-company-relationship-toggle" data-relationship-toggle aria-label="Collapse ${definition.label}" aria-expanded="true"><span class="fas fa-chevron-down" aria-hidden="true"></span></button>
            <span class="fas ${definition.icon}" aria-hidden="true"></span>
            <strong></strong>
            <span class="nexa-company-relationship-count"></span>
            <span class="nexa-company-relationship-actions">
                ${urls.add ? '<a data-relationship-add aria-label="Add related record"><span class="fas fa-plus" aria-hidden="true"></span></a>' : ''}
                <button type="button" data-relationship-menu-toggle aria-label="Relationship actions" aria-expanded="false"><span class="fas fa-ellipsis-h" aria-hidden="true"></span></button>
                <span class="nexa-company-relationship-menu" data-relationship-menu hidden>
                    <a data-relationship-view>View all</a>
                    ${urls.add ? '<a data-relationship-add-menu>Add new</a>' : ''}
                </span>
            </span>
        </header><div data-relationship-list></div>`;
        card.querySelector('strong').textContent = definition.label;
        card.querySelector('.nexa-company-relationship-count').textContent = String(definition.total);
        const list = card.querySelector('[data-relationship-list]');
        const collapseToggle = card.querySelector('[data-relationship-toggle]');
        collapseToggle.addEventListener('click', () => {
            const expanded = collapseToggle.getAttribute('aria-expanded') === 'true';
            collapseToggle.setAttribute('aria-expanded', String(!expanded));
            collapseToggle.setAttribute('aria-label', `${expanded ? 'Expand' : 'Collapse'} ${definition.label}`);
            collapseToggle.querySelector('.fas').className = `fas fa-chevron-${expanded ? 'right' : 'down'}`;
            list.hidden = expanded;
        });
        const viewLink = card.querySelector('[data-relationship-view]');
        viewLink.href = urls.view;
        card.querySelectorAll('[data-relationship-add], [data-relationship-add-menu]').forEach(link => link.href = urls.add);
        const toggle = card.querySelector('[data-relationship-menu-toggle]');
        const menu = card.querySelector('[data-relationship-menu]');
        toggle.addEventListener('click', event => {
            event.stopPropagation();
            const opening = menu.hidden;
            document.querySelectorAll('[data-relationship-menu]').forEach(other => other.hidden = true);
            document.querySelectorAll('[data-relationship-menu-toggle]').forEach(other => other.setAttribute('aria-expanded', 'false'));
            menu.hidden = !opening;
            toggle.setAttribute('aria-expanded', String(opening));
        });
        card.addEventListener('focusout', event => {
            if (event.relatedTarget && card.contains(event.relatedTarget)) return;
            menu.hidden = true;
            toggle.setAttribute('aria-expanded', 'false');
        });
        if (!definition.list.length) {
            const empty = document.createElement('span');
            empty.className = 'nexa-company-relationship-empty';
            empty.textContent = 'None linked';
            list.append(empty);
            return card;
        }
        definition.list.forEach(record => {
            const link = document.createElement('a');
            link.href = `#${definition.entityType}/view/${encodeURIComponent(record.id)}`;
            link.textContent = record.name || 'Unnamed record';
            list.append(link);
        });
        if (definition.total > definition.list.length && !['parentAccount', 'campaign'].includes(definition.key)) {
            const more = document.createElement('a');
            more.href = urls.view;
            more.textContent = `View all ${definition.total}`;
            more.className = 'nexa-company-relationship-more';
            list.append(more);
        }
        return card;
    }

    relationshipUrls(definition) {
        const id = encodeURIComponent(this.model.id);
        const name = encodeURIComponent(this.model.get('name') || '');
        const related = definition.key === 'parentAccount' || definition.key === 'campaign'
            ? `#${definition.entityType}/view/${encodeURIComponent(definition.list[0]?.id || '')}`
            : `#Account/related/${id}/${definition.key}`;
        const addMap = {
            subsidiaries: `#Account/create?parentAccountId=${id}&parentAccountName=${name}`,
            opportunities: `#Opportunity/create?accountId=${id}&accountName=${name}`,
            cases: `#Case/create?accountId=${id}&accountName=${name}`,
            documents: `#Account/related/${id}/documents`,
        };
        return {view: related, add: addMap[definition.key] || null};
    }

    async loadAccountEngagement(shell) {
        this.accountEngagement = {notes: [], tasks: [], meetings: [], calls: [], emails: [], activity: []};
        this.accountEngagementOffsets = {notes: 0, tasks: 0, meetings: 0, calls: 0, emails: 0, activity: 0};
        this.accountEngagementHasMore = {notes: false, tasks: false, meetings: false, calls: false, emails: false, activity: false};
        this.accountEngagementErrors = {};
        try {
            const contacts = await this.loadAccountContacts();
            this.accountContacts = contacts;
            this.renderContactRail(shell);
            this.accountContactMap = new Map(contacts.map(contact => [contact.id, contact]));
            this.accountContactEmailMap = new Map(contacts
                .filter(contact => contact.emailAddress)
                .map(contact => [String(contact.emailAddress).toLowerCase(), contact]));
            this.collapsedAccountEngagementIds = this.collapsedAccountEngagementIds || new Set();
            await Promise.all(['activity', 'notes', 'tasks', 'meetings', 'calls', 'emails']
                .map(key => this.loadTimelinePage(shell, key, false)));
        } catch (error) {
            this.accountEngagementError = true;
            this.accountContactsError = true;
            this.renderContactRail(shell);
        }

        ['activity', 'notes', 'tasks', 'meetings', 'calls', 'emails']
            .forEach(key => this.renderEngagementList(shell, key));
    }

    async loadTimelinePage(shell, key, append) {
        const button = shell.querySelector(`[data-nexa-engagement-more="${key}"]`);
        if (button?.dataset.loading === 'true') return;
        const offset = append ? (this.accountEngagementOffsets[key] || 0) : 0;
        if (button) {
            button.dataset.loading = 'true';
            button.disabled = true;
            button.textContent = 'Loading...';
        }
        try {
            const payload = await Espo.Ajax.getRequest(`Nexa/account/${encodeURIComponent(this.model.id)}/timeline`, {
                tab: key, offset, limit: 25,
            });
            const incoming = Array.isArray(payload?.list) ? payload.list : [];
            const records = incoming.map(record => this.decorateEngagement(record, record._entityType));
            const usable = records.filter(record => !this.isNoteComment(record) &&
                (key !== 'notes' || !this.isLoggedInteraction(record)));
            const groups = usable.reduce((result, record) => {
                result[record._entityType] = result[record._entityType] || [];
                result[record._entityType].push(record);
                return result;
            }, {});
            await Promise.all(Object.entries(groups)
                .map(([entityType, group]) => this.prepareEngagementPermissions(group, entityType)));
            this.accountEngagement[key] = append ? [...this.accountEngagement[key], ...usable] : usable;
            usable.forEach(record => this.collapsedAccountEngagementIds.add(this.engagementKey(record)));
            this.accountEngagementOffsets[key] = Number(payload?.nextOffset) || (offset + incoming.length);
            this.accountEngagementHasMore[key] = payload?.hasMore === true;
            this.accountEngagementErrors[key] = false;
            this.refreshEngagementFilterOptions(shell, key);
        } catch (error) {
            this.accountEngagementErrors[key] = true;
            if (!append) this.accountEngagement[key] = [];
        } finally {
            if (button) {
                button.dataset.loading = 'false';
                button.disabled = false;
                button.textContent = 'Load more';
            }
            this.renderEngagementList(shell, key);
        }
    }

    refreshEngagementFilterOptions(shell, key) {
        const records = this.accountEngagement?.[key] || [];
        const ownerSelect = shell.querySelector(`[data-nexa-engagement-owner="${key}"]`);
        const statusSelect = shell.querySelector(`[data-nexa-engagement-status="${key}"]`);
        if (ownerSelect) {
            const selected = ownerSelect.value || 'all';
            const owners = new Map(records.map(record => [record.assignedUserId || record.createdById, record.assignedUserName || record.createdByName])
                .filter(([id, name]) => id && name));
            ownerSelect.querySelectorAll('option[data-dynamic]').forEach(option => option.remove());
            [...owners.entries()].sort((a, b) => a[1].localeCompare(b[1])).forEach(([id, name]) => {
                const option = document.createElement('option');
                option.value = id;
                option.textContent = name;
                option.dataset.dynamic = 'true';
                ownerSelect.append(option);
            });
            ownerSelect.value = [...ownerSelect.options].some(option => option.value === selected) ? selected : 'all';
        }
        if (statusSelect) {
            const selected = statusSelect.value || 'all';
            const statuses = [...new Set(records.map(record => record.status).filter(Boolean))].sort();
            statusSelect.querySelectorAll('option[data-dynamic]').forEach(option => option.remove());
            statuses.forEach(status => {
                const option = document.createElement('option');
                option.value = status;
                option.textContent = status;
                option.dataset.dynamic = 'true';
                statusSelect.append(option);
            });
            statusSelect.value = statuses.includes(selected) ? selected : 'all';
        }
    }

    /** Resolve both the many-to-many and primary Account links, then de-duplicate. */
    async loadAccountContacts() {
        const responses = await Promise.allSettled([
            this.loadRelatedContacts('contacts'),
            this.loadRelatedContacts('contactsPrimary'),
        ]);
        const contacts = new Map();
        responses.forEach(result => {
            if (result.status !== 'fulfilled') return;
            result.value.forEach(contact => contacts.set(contact.id, contact));
        });
        return [...contacts.values()];
    }

    async loadRelatedContacts(link) {
        const records = [];
        const id = encodeURIComponent(this.model.id);
        const maxSize = 200;
        let offset = 0;
        let total = null;
        do {
            const payload = await Espo.Ajax.getRequest(`Account/${id}/${link}`, {
                select: 'id,name,title,emailAddress,phoneNumber,assignedUserId,assignedUserName', maxSize, offset,
            });
            const page = Array.isArray(payload?.list) ? payload.list : [];
            records.push(...page);
            total = Number.isFinite(Number(payload?.total)) ? Number(payload.total) : -1;
            offset += page.length;
            if (!page.length) break;
        } while (total >= 0 ? offset < total : records.length && records.length % maxSize === 0);
        return records;
    }

    renderContactRail(shell) {
        const list = shell.querySelector('[data-nexa-company-contact-cards]');
        const count = shell.querySelector('[data-nexa-company-contact-count]');
        if (!list || !count) return;

        const contacts = [...(this.accountContacts || [])];
        count.textContent = `(${contacts.length})`;
        list.replaceChildren();
        if (this.accountContactsError) {
            list.append(this.contactRailEmpty('Contacts are unavailable for your current permissions.', 'fa-exclamation-circle'));
            return;
        }

        const filtered = contacts.sort((left, right) => String(left.name || '').localeCompare(String(right.name || '')));
        if (!filtered.length) {
            list.append(this.contactRailEmpty('No contacts are associated yet.', 'fa-user'));
            return;
        }
        filtered.slice(0, 8).forEach(contact => list.append(this.contactRailCard(contact)));
    }

    contactRailCard(contact) {
        const card = document.createElement('article');
        card.className = 'nexa-company-contact-card';
        card.innerHTML = `
            <div class="nexa-company-contact-card-heading">
                <span class="nexa-company-contact-avatar" aria-hidden="true"></span>
                <a class="nexa-company-contact-name"></a>
                <a class="nexa-company-contact-open" aria-label="Open contact"><span class="fas fa-arrow-up-right-from-square fa-external-link-alt" aria-hidden="true"></span></a>
            </div>
            <p class="nexa-company-contact-title"></p>
            <dl><div><dt>Email</dt><dd data-email></dd></div><div><dt>Phone</dt><dd data-phone></dd></div></dl>`;

        const name = contact.name || 'Unnamed contact';
        const url = `#Contact/view/${encodeURIComponent(contact.id)}`;
        card.querySelector('.nexa-company-contact-avatar').textContent = name.trim().charAt(0).toUpperCase() || '?';
        const nameLink = card.querySelector('.nexa-company-contact-name');
        nameLink.href = url;
        nameLink.textContent = name;
        card.querySelector('.nexa-company-contact-open').href = url;
        card.querySelector('.nexa-company-contact-title').textContent = contact.title || 'Title not recorded';
        this.setContactRailLink(card.querySelector('[data-email]'), contact.emailAddress, 'mailto:');
        this.setContactRailLink(card.querySelector('[data-phone]'), contact.phoneNumber, 'tel:');
        return card;
    }

    setContactRailLink(target, value, scheme) {
        if (!value) {
            target.textContent = 'Not recorded';
            return;
        }
        const link = document.createElement('a');
        link.href = `${scheme}${value}`;
        link.textContent = value;
        target.append(link);
    }

    contactRailEmpty(message, icon) {
        const empty = document.createElement('div');
        empty.className = 'nexa-company-contact-empty';
        empty.innerHTML = `<span class="fas ${icon}" aria-hidden="true"></span><span></span>`;
        empty.lastElementChild.textContent = message;
        return empty;
    }

    async prepareEngagementPermissions(records, entityType) {
        await Promise.all(records.map(async record => {
            const model = await this.getModelFactory().create(entityType);
            model.set(record);
            model.id = record.id;
            record.canPin = entityType === 'Note'
                ? this.getAcl().checkModel(this.model, 'edit') === true
                : this.getAcl().checkModel(model, 'edit') === true;
            record.canDelete = this.getAcl().checkModel(model, 'delete') === true;
            record.isPinned = record.isPinned === true;
        }));
    }

    decorateEngagement(record, entityType) {
        return {...record, _entityType: entityType, _contact: this.resolveEngagementContact(record, entityType)};
    }

    isNoteComment(record) {
        return /^<!-- nexa-note-(?:comment|reply):/i.test(String(record.post || '').trim());
    }

    isLoggedInteraction(record) {
        return /^\[[^\]]+(?: - (?:Outbound|Inbound))?\]/i.test(String(record.post || '').trim());
    }

    resolveEngagementContact(record, entityType) {
        if (record.parentType === 'Contact') return this.accountContactMap.get(record.parentId) || null;
        const ids = Array.isArray(record.contactsIds) ? record.contactsIds : [];
        for (const id of ids) if (this.accountContactMap.has(id)) return this.accountContactMap.get(id);
        if (entityType === 'Email') {
            const addresses = `${record.to || ''} ${record.cc || ''} ${record.fromString || ''}`.toLowerCase();
            for (const [email, contact] of this.accountContactEmailMap) if (addresses.includes(email)) return contact;
        }
        return null;
    }

    renderEngagementList(shell, key) {
        const list = shell.querySelector(`[data-nexa-engagement-list="${key}"]`);
        const more = shell.querySelector(`[data-nexa-engagement-more="${key}"]`);
        if (!list) return;

        const allRecords = this.accountEngagement?.[key] || [];
        const query = this.accountEngagementSearch?.[key] || '';
        const filter = this.accountEngagementFilters?.[key] || {period: 'all', owner: 'all', status: 'all'};
        const records = allRecords.filter(record => {
            const ownerId = record.assignedUserId || record.createdById || '';
            const matchesOwner = filter.owner === 'all' || ownerId === filter.owner ||
                (filter.owner === 'me' && ownerId === this.getUser().id);
            const matchesStatus = filter.status === 'all' || record.status === filter.status;
            return (!query || this.engagementSearchText(record).includes(query)) && matchesOwner && matchesStatus &&
                this.engagementMatchesPeriod(record, filter.period);
        });
        list.replaceChildren();

        if (more) more.hidden = !this.accountEngagementHasMore?.[key];
        if (this.accountEngagementError || this.accountEngagementErrors?.[key]) {
            list.append(this.engagementEmpty('Company engagement is temporarily unavailable.', 'fa-exclamation-circle'));
            return;
        }
        if (!records.length) {
            list.append(this.engagementEmpty(query ? 'No matching activity was found.' : 'No activity has been recorded yet.', 'fa-inbox'));
            return;
        }
        records
            .sort((left, right) => Number(right.isPinned) - Number(left.isPinned) || this.engagementTimestamp(right) - this.engagementTimestamp(left))
            .forEach(record => list.append(this.engagementRecord(record)));
    }

    engagementMatchesPeriod(record, period) {
        if (!period || period === 'all') return true;
        const timestamp = this.engagementTimestamp(record);
        if (!timestamp) return false;
        const date = new Date(timestamp);
        const now = new Date();
        const startToday = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        const start = new Date(startToday);
        const end = new Date(startToday);
        end.setDate(end.getDate() + 1);
        if (period === 'yesterday') {
            start.setDate(start.getDate() - 1);
            end.setDate(end.getDate() - 1);
        } else if (period === 'thisWeek') {
            start.setDate(start.getDate() - ((start.getDay() + 6) % 7));
        } else if (period === 'last7') start.setDate(start.getDate() - 7);
        else if (period === 'thisMonth') start.setDate(1);
        else if (period === 'last30') start.setDate(start.getDate() - 30);
        else if (period === 'last90') start.setDate(start.getDate() - 90);
        else if (period === 'thisYear') start.setMonth(0, 1);
        if (!['today', 'yesterday'].includes(period)) end.setTime(now.getTime() + 1);
        return date >= start && date < end;
    }

    engagementRecord(record) {
        const item = document.createElement('article');
        const collapsed = this.collapsedAccountEngagementIds?.has(this.engagementKey(record)) !== false;
        const title = this.engagementTitle(record);
        const owner = record.assignedUserName || record.createdByName || record.fromName || 'Owner not recorded';
        const contactName = record._contact?.name || 'Company-level';
        const iconMap = {Note: 'fa-sticky-note', Task: 'fa-check-square', Meeting: 'fa-calendar-alt', Call: 'fa-phone', Email: 'fa-envelope'};
        const url = record._entityType === 'Note'
            ? (record._contact ? `#Contact/view/${encodeURIComponent(record._contact.id)}` : `#Account/view/${encodeURIComponent(this.model.id)}`)
            : `#${record._entityType}/view/${encodeURIComponent(record.id)}`;

        const canPin = record.canPin === true;
        const canDelete = record.canDelete === true;
        const deleteHelp = "You don't have permission to delete this activity. Ask your admin to grant permission.";
        item.className = `nexa-company-engagement-record nexa-activity-card${collapsed ? ' is-collapsed' : ''}${record.isPinned ? ' is-pinned' : ''}`;
        item.dataset.nexaCompanyEngagementType = record._entityType;
        item.dataset.nexaCompanyEngagementId = record.id;
        item.innerHTML = `
            <header>
                <button type="button" class="nexa-activity-toggle" data-nexa-company-engagement-toggle aria-expanded="${!collapsed}">
                    <span class="fas fa-chevron-${collapsed ? 'right' : 'down'}" aria-hidden="true"></span>
                    <span class="nexa-company-engagement-icon fas ${iconMap[record._entityType] || 'fa-circle'}" aria-hidden="true"></span>
                    <span class="nexa-activity-heading"><strong></strong><span data-subtitle></span></span>
                </button>
                <div class="nexa-note-header-meta">
                    <div class="nexa-note-actions" data-nexa-company-engagement-actions${collapsed ? ' hidden' : ''}>
                        <button type="button" class="nexa-note-actions-toggle" data-nexa-company-engagement-actions-toggle aria-expanded="false">Actions <span class="fas fa-caret-down" aria-hidden="true"></span></button>
                        <div class="nexa-note-actions-menu" data-nexa-company-engagement-actions-menu hidden>
                            <button type="button" data-nexa-company-engagement-pin${canPin ? '' : ' disabled aria-disabled="true"'}><span class="fas fa-thumbtack" aria-hidden="true"></span>${record.isPinned ? 'Unpin' : 'Pin'}</button>
                            ${canDelete ? '<button type="button" class="is-danger" data-nexa-company-engagement-delete><span class="far fa-trash-alt" aria-hidden="true"></span>Delete</button>' : `<span class="nexa-note-action-disabled" data-tooltip="${deleteHelp}" tabindex="0"><button type="button" class="is-danger" disabled aria-disabled="true"><span class="far fa-trash-alt" aria-hidden="true"></span>Delete</button></span>`}
                        </div>
                    </div>
                    <time></time>
                </div>
            </header>
            <p class="nexa-activity-preview"${collapsed ? '' : ' hidden'}></p>
            <div class="nexa-activity-details"${collapsed ? ' hidden' : ''}>
                <p class="nexa-company-engagement-preview"></p>
                <p class="nexa-company-engagement-meta"><span data-contact></span><span data-owner></span><span data-status></span></p>
                <a class="nexa-company-engagement-view">View record <span class="fas fa-arrow-right" aria-hidden="true"></span></a>
            </div>`;
        const link = item.querySelector('a');
        item.querySelector('.nexa-activity-heading strong').textContent = title;
        item.querySelector('[data-subtitle]').textContent = `${record._entityType} by ${owner}`;
        item.querySelector('[data-contact]').textContent = contactName;
        item.querySelector('[data-owner]').textContent = owner;
        item.querySelector('[data-status]').textContent = record.status || record._entityType;
        item.querySelector('time').textContent = this.engagementDate(record);
        const preview = this.engagementPreview(record);
        item.querySelector('.nexa-activity-preview').textContent = preview;
        item.querySelector('.nexa-company-engagement-preview').textContent = preview;
        link.href = url;
        return item;
    }

    engagementKey(record) {
        return `${record._entityType}:${record.id}`;
    }

    findEngagementRecord(entityType, id) {
        return Object.values(this.accountEngagement || {}).flat()
            .find(record => record._entityType === entityType && record.id === id) || null;
    }

    handleEngagementAction(event, shell) {
        if (!event.target.closest('[data-nexa-company-engagement-actions]')) {
            shell.querySelectorAll('[data-nexa-company-engagement-actions-menu]').forEach(node => node.hidden = true);
            shell.querySelectorAll('[data-nexa-company-engagement-actions-toggle]').forEach(node => node.setAttribute('aria-expanded', 'false'));
        }
        const card = event.target.closest('[data-nexa-company-engagement-id]');
        if (!card) return;
        const entityType = card.dataset.nexaCompanyEngagementType;
        const id = card.dataset.nexaCompanyEngagementId;
        const record = this.findEngagementRecord(entityType, id);
        if (!record) return;

        if (event.target.closest('[data-nexa-company-engagement-toggle]')) {
            const key = this.engagementKey(record);
            if (this.collapsedAccountEngagementIds.has(key)) this.collapsedAccountEngagementIds.delete(key);
            else this.collapsedAccountEngagementIds.add(key);
            this.renderAllEngagementLists(shell);
            return;
        }
        const actionsToggle = event.target.closest('[data-nexa-company-engagement-actions-toggle]');
        if (actionsToggle) {
            event.stopPropagation();
            const menu = actionsToggle.parentElement.querySelector('[data-nexa-company-engagement-actions-menu]');
            const opening = menu.hidden;
            shell.querySelectorAll('[data-nexa-company-engagement-actions-menu]').forEach(node => node.hidden = true);
            shell.querySelectorAll('[data-nexa-company-engagement-actions-toggle]').forEach(node => node.setAttribute('aria-expanded', 'false'));
            menu.hidden = !opening;
            actionsToggle.setAttribute('aria-expanded', String(opening));
            return;
        }
        const pin = event.target.closest('[data-nexa-company-engagement-pin]');
        if (pin) {
            this.toggleEngagementPinned(record, !record.isPinned, pin, shell);
            return;
        }
        if (event.target.closest('[data-nexa-company-engagement-delete]')) this.openEngagementDeleteDialog(record, shell);
    }

    renderAllEngagementLists(shell) {
        ['activity', 'notes', 'tasks', 'meetings', 'calls', 'emails'].forEach(key => this.renderEngagementList(shell, key));
    }

    async toggleEngagementPinned(record, pinned, button, shell) {
        if (!record?.canPin || button.dataset.saving === 'true') return;
        button.dataset.saving = 'true';
        button.disabled = true;
        try {
            if (record._entityType === 'Note') {
                const path = `Note/${encodeURIComponent(record.id)}/pin`;
                if (pinned) await Espo.Ajax.postRequest(path);
                else await Espo.Ajax.deleteRequest(path);
            } else {
                const model = await this.getModelFactory().create(record._entityType);
                model.id = record.id;
                await model.save({isPinned: pinned}, {patch: true});
            }
            Object.values(this.accountEngagement).flat()
                .filter(item => item._entityType === record._entityType && item.id === record.id)
                .forEach(item => item.isPinned = pinned);
            this.renderAllEngagementLists(shell);
            Espo.Ui.success(pinned ? 'Activity pinned' : 'Activity unpinned');
        } catch (error) {
            button.disabled = false;
            button.dataset.saving = 'false';
            Espo.Ui.error('The activity could not be updated. Check your access and try again.');
        }
    }

    openEngagementDeleteDialog(record, shell) {
        if (!record?.canDelete) return;
        this.accountEngagementDeleteDialog?.remove();
        const label = record._entityType.toLowerCase();
        const overlay = document.createElement('div');
        overlay.className = 'nexa-note-delete-overlay';
        overlay.innerHTML = `<section class="nexa-note-delete-dialog" role="dialog" aria-modal="true" aria-labelledby="nexa-account-engagement-delete-title"><header><div><p>Delete ${label}</p><h2 id="nexa-account-engagement-delete-title">Delete this ${label}?</h2></div><button type="button" class="nexa-dialog-close" data-close aria-label="Close"><span class="fas fa-times" aria-hidden="true"></span></button></header><div class="nexa-note-delete-content"><p>This ${label} will be removed from the company timeline. This action cannot be undone from this page.</p><p class="nexa-note-delete-error" role="alert" hidden></p></div><footer><button type="button" class="btn btn-default" data-cancel>Cancel</button><button type="button" class="btn btn-danger" data-confirm><span class="far fa-trash-alt" aria-hidden="true"></span><span>Delete ${label}</span></button></footer></section>`;
        document.body.append(overlay);
        this.accountEngagementDeleteDialog = overlay;
        const close = () => {
            overlay.remove();
            if (this.accountEngagementDeleteDialog === overlay) this.accountEngagementDeleteDialog = null;
        };
        overlay.querySelector('[data-close]').addEventListener('click', close);
        overlay.querySelector('[data-cancel]').addEventListener('click', close);
        overlay.addEventListener('click', event => { if (event.target === overlay) close(); });
        overlay.addEventListener('keydown', event => { if (event.key === 'Escape') close(); });
        overlay.querySelector('[data-confirm]').addEventListener('click', event => this.deleteEngagementRecord(record, event.currentTarget, shell));
        window.setTimeout(() => overlay.querySelector('[data-cancel]')?.focus(), 0);
    }

    async deleteEngagementRecord(record, button, shell) {
        if (!record?.canDelete || button.dataset.saving === 'true') return;
        const error = this.accountEngagementDeleteDialog?.querySelector('.nexa-note-delete-error');
        button.dataset.saving = 'true';
        button.disabled = true;
        button.classList.add('is-loading');
        try {
            await Espo.Ajax.deleteRequest(`${record._entityType}/${encodeURIComponent(record.id)}`);
            Object.keys(this.accountEngagement).forEach(key => {
                this.accountEngagement[key] = this.accountEngagement[key]
                    .filter(item => !(item._entityType === record._entityType && item.id === record.id));
            });
            this.collapsedAccountEngagementIds.delete(this.engagementKey(record));
            this.accountEngagementDeleteDialog?.remove();
            this.accountEngagementDeleteDialog = null;
            this.renderAllEngagementLists(shell);
            Espo.Ui.success('Activity deleted');
        } catch (deleteError) {
            error.textContent = 'The activity could not be deleted. Check your permission and try again.';
            error.hidden = false;
            button.dataset.saving = 'false';
            button.disabled = false;
            button.classList.remove('is-loading');
        }
    }

    engagementTitle(record) {
        if (record._entityType === 'Note') return this.engagementPreview(record) || 'Contact note';
        return record.name || record.subject || `${record._entityType} record`;
    }

    engagementPreview(record) {
        const value = record.post || record.description || record.subject || '';
        const container = document.createElement('div');
        container.innerHTML = this.getHelper().sanitizeHtml(String(value));
        return container.textContent.replace(/\s+/g, ' ').trim().slice(0, 180);
    }

    engagementSearchText(record) {
        return (`${this.engagementTitle(record)} ${this.engagementPreview(record)} ${record.status || ''} ` +
            `${record.assignedUserName || record.createdByName || ''} ${record._contact?.name || ''}`).toLowerCase();
    }

    engagementTimestamp(record) {
        const value = record.dateStart || record.dateSent || record.sendAt || record.dateEnd || record.createdAt;
        const time = Date.parse(String(value || '').replace(' ', 'T') + 'Z');
        return Number.isNaN(time) ? 0 : time;
    }

    engagementDate(record) {
        const value = record.dateStart || record.dateSent || record.sendAt || record.dateEnd || record.createdAt;
        if (!value) return 'Date not recorded';
        const date = new Date(String(value).replace(' ', 'T') + 'Z');
        return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat(undefined, {
            day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
        }).format(date);
    }

    engagementEmpty(message, icon) {
        const empty = document.createElement('div');
        empty.className = 'nexa-company-engagement-empty';
        empty.innerHTML = `<span class="fas ${icon}" aria-hidden="true"></span><span></span>`;
        empty.querySelector('span:last-child').textContent = message;
        return empty;
    }
});
