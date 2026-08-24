define('custom:views/account/record/detail-workspace', ['views/record/detail', 'helpers/record-modal', 'custom:helpers/tenant-images', 'custom:helpers/tenant-files'], (Dep, RecordModalHelper, TenantImages, TenantFiles) => class extends Dep {
    setup() {
        super.setup();
        TenantFiles.install();
        document.body.classList.add('nexa-account-detail-page');
        this.listenTo(this.model, 'sync change', () => this.refreshCompanySummary());
        this.once('remove', () => {
            document.body.classList.remove('nexa-account-detail-page');
            this.companyWorkspaceObserver?.disconnect();
            this.companyAssociationResizeObserver?.disconnect();
            this.accountEngagementDeleteDialog?.remove();
            this.closeAccountCommandMenu();
            this.closeAccountInteractionDialog();
            this.closeAccountNoteDialog();
            this.clearAccountCommentEditors();
            this.releaseCompanyAvatar();
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
                    <div class="nexa-company-quick-actions" role="toolbar" aria-label="Company actions">
                        ${this.companyActionButton('note', 'fas fa-sticky-note', 'Note', 'Create a company note')}
                        ${this.companyActionButton('task', 'far fa-check-square', 'Task', 'Create a company task')}
                        ${this.companyActionButton('meeting', 'far fa-calendar', 'Meeting', 'Schedule a company meeting')}
                        ${this.companyMoreActionButton()}
                    </div>
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
                        <div><dt>Lifecycle stage</dt><dd data-nexa-company-field="lifecycleStage"></dd></div>
                        <div><dt>Lead status</dt><dd data-nexa-company-field="leadStatus"></dd></div>
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
        this.bindCompanyQuickActions(shell);
        this.bindContactRail(shell);
        this.refreshCompanySummary();
        this.loadCompanyMetrics(shell);
        this.loadRelationshipOverview(shell);
        this.loadAccountEngagement(shell);
    }

    companyActionButton(type, icon, label, tooltip) {
        return `<button type="button" class="btn btn-link" data-nexa-company-action="${type}"
            title="${tooltip}" aria-label="${tooltip}"><span class="${icon}" aria-hidden="true"></span><span>${label}</span></button>`;
    }

    companyMoreActionButton() {
        return `<button type="button" class="btn btn-link" data-nexa-company-more-actions
            title="More company actions" aria-label="More company actions" aria-haspopup="dialog" aria-expanded="false">
            <span class="fas fa-ellipsis-h" aria-hidden="true"></span><span>More</span></button>`;
    }

    bindCompanyQuickActions(shell) {
        shell.querySelector('[data-nexa-company-action="note"]')?.addEventListener('click', event =>
            this.openAccountNoteDialog(event.currentTarget));
        shell.querySelector('[data-nexa-company-action="task"]')?.addEventListener('click', () =>
            this.openAccountRelatedRecord('Task', 'tasks'));
        shell.querySelector('[data-nexa-company-action="meeting"]')?.addEventListener('click', () =>
            this.openAccountRelatedRecord('Meeting', 'meetings'));
        shell.querySelector('[data-nexa-company-more-actions]')?.addEventListener('click', event => {
            event.stopPropagation();
            this.toggleAccountCommandMenu(event.currentTarget);
        });
    }

    accountCommandGroups() {
        return [{
            label: 'Log communication',
            actions: [
                ['sms', 'fas fa-comment-alt', 'Log SMS'],
                ['whatsapp', null, 'Log WhatsApp message', 'whatsapp.svg'],
                ['linkedin', null, 'Log LinkedIn message', 'linkedin.svg'],
                ['call', 'fas fa-phone-alt', 'Log call'],
                ['meeting', 'far fa-calendar-check', 'Log meeting'],
                ['email', 'far fa-envelope', 'Log email'],
                ['postal-mail', 'fas fa-mail-bulk', 'Log postal mail'],
                ['other', 'far fa-sticky-note', 'Log other interaction'],
            ],
        }];
    }

    accountCommandIcon(icon, asset) {
        if (asset) {
            const source = `${this.getBasePath()}client/custom/img/social/${asset}`;
            return `<img class="nexa-command-brand-icon" src="${source}" alt="" aria-hidden="true">`;
        }
        return `<span class="${icon}" aria-hidden="true"></span>`;
    }

    toggleAccountCommandMenu(anchor) {
        if (this.accountCommandMenu) {
            this.closeAccountCommandMenu();
            return;
        }
        const menu = document.createElement('section');
        menu.className = 'nexa-customer-command-menu';
        menu.dataset.nexaAccountCommandMenu = 'true';
        menu.setAttribute('role', 'dialog');
        menu.setAttribute('aria-label', 'More company actions');
        menu.innerHTML = `
            <div class="nexa-command-search"><span class="fas fa-search" aria-hidden="true"></span>
                <input type="search" class="form-control" data-nexa-account-command-search placeholder="Search company actions" aria-label="Search company actions">
            </div>
            <div class="nexa-command-groups">${this.accountCommandGroups().map(group => `
                <section class="nexa-command-group" data-nexa-account-command-group><h3>${group.label}</h3>
                    ${group.actions.map(([action, icon, label, asset]) => `<button type="button" data-nexa-account-command="${action}" data-nexa-account-command-label="${label.toLowerCase()}">${this.accountCommandIcon(icon, asset)}<span>${label}</span></button>`).join('')}
                </section>`).join('')}
            </div><p class="nexa-command-empty" data-nexa-account-command-empty hidden>No matching company actions.</p>`;
        document.body.append(menu);
        this.accountCommandMenu = menu;
        this.accountCommandAnchor = anchor;
        anchor.setAttribute('aria-expanded', 'true');
        this.positionAccountCommandMenu();

        const search = menu.querySelector('[data-nexa-account-command-search]');
        search.addEventListener('input', () => this.filterAccountCommands(search.value));
        menu.addEventListener('click', event => {
            const command = event.target.closest('[data-nexa-account-command]');
            if (!command) return;
            const returnFocus = this.accountCommandAnchor;
            const channel = command.dataset.nexaAccountCommand;
            this.closeAccountCommandMenu();
            this.openAccountInteractionDialog(channel, returnFocus);
        });
        menu.addEventListener('keydown', event => this.handleAccountCommandKeys(event));
        this.accountCommandOutsideHandler = event => {
            if (!menu.contains(event.target) && event.target !== anchor) this.closeAccountCommandMenu();
        };
        this.accountCommandPositionHandler = () => this.positionAccountCommandMenu();
        document.addEventListener('click', this.accountCommandOutsideHandler);
        window.addEventListener('resize', this.accountCommandPositionHandler);
        window.addEventListener('scroll', this.accountCommandPositionHandler, true);
        window.setTimeout(() => search.focus(), 0);
    }

    positionAccountCommandMenu() {
        if (!this.accountCommandMenu || !this.accountCommandAnchor?.isConnected) return;
        const rect = this.accountCommandAnchor.getBoundingClientRect();
        const margin = 12;
        const width = Math.min(330, window.innerWidth - margin * 2);
        this.accountCommandMenu.style.width = `${width}px`;
        this.accountCommandMenu.style.left = `${Math.min(Math.max(margin, rect.left), window.innerWidth - width - margin)}px`;
        this.accountCommandMenu.style.top = `${rect.bottom + 7}px`;
    }

    filterAccountCommands(query) {
        if (!this.accountCommandMenu) return;
        const term = query.trim().toLowerCase();
        let visible = 0;
        this.accountCommandMenu.querySelectorAll('[data-nexa-account-command]').forEach(button => {
            button.hidden = Boolean(term) && !button.dataset.nexaAccountCommandLabel.includes(term);
            if (!button.hidden) visible++;
        });
        this.accountCommandMenu.querySelectorAll('[data-nexa-account-command-group]').forEach(group => {
            group.hidden = !group.querySelector('[data-nexa-account-command]:not([hidden])');
        });
        this.accountCommandMenu.querySelector('[data-nexa-account-command-empty]').hidden = visible !== 0;
    }

    handleAccountCommandKeys(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            const anchor = this.accountCommandAnchor;
            this.closeAccountCommandMenu();
            anchor?.focus();
            return;
        }
        if (!['ArrowDown', 'ArrowUp'].includes(event.key)) return;
        const buttons = [...this.accountCommandMenu.querySelectorAll('[data-nexa-account-command]:not([hidden])')];
        if (!buttons.length) return;
        event.preventDefault();
        const current = buttons.indexOf(document.activeElement);
        const offset = event.key === 'ArrowDown' ? 1 : -1;
        buttons[(current + offset + buttons.length) % buttons.length].focus();
    }

    closeAccountCommandMenu() {
        document.removeEventListener('click', this.accountCommandOutsideHandler);
        window.removeEventListener('resize', this.accountCommandPositionHandler);
        window.removeEventListener('scroll', this.accountCommandPositionHandler, true);
        this.accountCommandAnchor?.setAttribute('aria-expanded', 'false');
        this.accountCommandMenu?.remove();
        this.accountCommandMenu = null;
        this.accountCommandAnchor = null;
        this.accountCommandOutsideHandler = null;
        this.accountCommandPositionHandler = null;
    }

    async openAccountRelatedRecord(entityType, tab) {
        if (!this.getAcl().checkScope(entityType, 'create')) {
            Espo.Ui.error(`You do not have permission to create ${entityType.toLowerCase()} records.`);
            return;
        }
        const attributes = {
            parentType: 'Account', parentId: this.model.id, parentName: this.model.get('name'),
            assignedUserId: this.getUser().id, assignedUserName: this.getUser().get('name'),
        };
        if (entityType === 'Meeting') attributes.status = 'Planned';
        try {
            await new RecordModalHelper().showCreate(this, {
                entityType, attributes, focusForCreate: true,
                afterSave: () => this.refreshAccountEngagement([tab, 'activity'], tab),
            });
        } catch (error) {
            Espo.Ui.error(this.translate('Error occurred'));
        }
    }

    async openAccountNoteDialog(returnFocus = null) {
        if (!this.getAcl().checkScope('Note', 'create')) {
            Espo.Ui.error('You do not have permission to create notes.');
            return;
        }
        this.closeAccountNoteDialog();
        const overlay = document.createElement('div');
        overlay.className = 'nexa-note-overlay';
        overlay.dataset.nexaAccountNoteDialog = 'true';
        overlay.innerHTML = `<section class="nexa-note-dialog" role="dialog" aria-modal="true" aria-labelledby="nexa-account-note-title">
            <header><div><p>Company workspace</p><h2 id="nexa-account-note-title">Add a note</h2></div><button type="button" class="nexa-dialog-close" data-close aria-label="Close note"><span class="fas fa-times" aria-hidden="true"></span></button></header>
            <form data-nexa-account-note-form><div class="nexa-note-recipient"><span>For</span><strong></strong></div>
                <p class="nexa-note-help">Add context that your team should retain on this company record.</p>
                <div class="nexa-native-rich-editor" data-nexa-account-note-editor><div class="nexa-note-editor-loading"><span class="fas fa-circle-notch fa-spin" aria-hidden="true"></span><span>Loading editor</span></div></div>
                <p class="nexa-note-error" data-error role="alert" hidden></p>
                <footer><button type="button" class="btn btn-default" data-close>Cancel</button><button type="submit" class="btn btn-primary" data-save disabled><span class="fas fa-check" aria-hidden="true"></span><span>Add note</span></button></footer>
            </form></section>`;
        overlay.querySelector('.nexa-note-recipient strong').textContent = this.model.get('name') || 'Company';
        document.body.append(overlay);
        this.accountNoteDialog = overlay;
        this.accountNoteReturnFocus = returnFocus;
        overlay.querySelectorAll('[data-close]').forEach(button => button.addEventListener('click', () => this.closeAccountNoteDialog()));
        overlay.addEventListener('mousedown', event => { if (event.target === overlay) this.closeAccountNoteDialog(); });
        overlay.addEventListener('keydown', event => this.trapAccountDialogKeys(event, () => this.closeAccountNoteDialog()));
        overlay.querySelector('form').addEventListener('submit', event => { event.preventDefault(); this.saveAccountNote(event.currentTarget); });
        try {
            this.accountNoteModel = await this.getModelFactory().create('Note');
            if (!overlay.isConnected) return;
            this.accountNoteEditor = await this.createView('nexaAccountNoteEditor', 'custom:views/fields/nexa-rich-text', {
                fullSelector: '[data-nexa-account-note-editor]', model: this.accountNoteModel,
                name: 'post', mode: 'edit', params: {height: 300, minHeight: 240},
            });
            await this.accountNoteEditor.render();
            overlay.querySelector('[data-save]').disabled = false;
            window.setTimeout(() => overlay.querySelector('.note-editable')?.focus(), 0);
        } catch (error) {
            const message = overlay.querySelector('[data-error]');
            message.textContent = 'The rich-text editor could not be loaded.';
            message.hidden = false;
        }
    }

    async saveAccountNote(form) {
        if (!this.accountNoteEditor || this.accountNoteSavePending) return;
        this.accountNoteEditor.fetchToModel();
        const content = String(this.accountNoteModel.get('post') || '').trim();
        const plain = document.createElement('div');
        plain.innerHTML = this.getHelper().sanitizeHtml(content);
        const error = form.querySelector('[data-error]');
        if (!plain.textContent.replace(/\u00a0/g, ' ').trim() &&
            !plain.querySelector('img, table, hr, a[data-nexa-file-id]')) {
            error.textContent = 'Enter a note before saving.';
            error.hidden = false;
            return;
        }
        const submit = form.querySelector('[data-save]');
        this.accountNoteSavePending = true;
        submit.disabled = true;
        submit.classList.add('is-loading');
        try {
            const note = await this.getModelFactory().create('Note');
            note.set({type: 'Post', post: `<!-- nexa-account-note -->\n${content}`, parentType: 'Account', parentId: this.model.id});
            await note.save(null);
            this.closeAccountNoteDialog();
            Espo.Ui.success('Note added');
            await this.refreshAccountEngagement(['notes', 'activity'], 'notes');
        } catch (saveError) {
            error.textContent = 'The note could not be saved. Check your access and try again.';
            error.hidden = false;
            submit.disabled = false;
            submit.classList.remove('is-loading');
        } finally {
            this.accountNoteSavePending = false;
        }
    }

    closeAccountNoteDialog() {
        const returnFocus = this.accountNoteReturnFocus;
        if (this.getView('nexaAccountNoteEditor')) this.clearView('nexaAccountNoteEditor');
        this.accountNoteDialog?.remove();
        this.accountNoteDialog = null;
        this.accountNoteEditor = null;
        this.accountNoteModel = null;
        this.accountNoteReturnFocus = null;
        this.accountNoteSavePending = false;
        returnFocus?.focus?.();
    }

    accountInteractionChannels() {
        return {sms: 'SMS', whatsapp: 'WhatsApp message', linkedin: 'LinkedIn message', call: 'Call', meeting: 'Meeting', email: 'Email', 'postal-mail': 'Postal mail', other: 'Other interaction'};
    }

    accountCallOutcomeOptions() {
        return ['Busy', 'Connected', 'Left live message', 'Left voicemail', 'Meeting booked', 'No answer', 'Wrong number'];
    }

    accountMeetingOutcomeOptions() {
        return ['Scheduled', 'Completed', 'Rescheduled', 'No show', 'Canceled'];
    }

    accountDurationOptions() {
        const options = [];
        for (let minutes = 15; minutes <= 480; minutes += 15) {
            const hours = Math.floor(minutes / 60);
            const remainder = minutes % 60;
            const parts = [];
            if (hours) parts.push(`${hours} Hour${hours > 1 ? 's' : ''}`);
            if (remainder) parts.push(`${remainder} Minutes`);
            options.push([minutes, parts.join(' ')]);
        }
        return options;
    }

    accountDurationPickerHtml() {
        return `<div class="nexa-duration-field"><label for="nexa-account-duration-search">Duration</label>
            <div class="nexa-duration-picker" data-nexa-duration-picker>
                <div class="nexa-duration-input"><span class="fas fa-search" aria-hidden="true"></span><input id="nexa-account-duration-search" type="search" class="form-control" data-nexa-duration-search role="combobox" aria-autocomplete="list" aria-controls="nexa-account-duration-options" aria-expanded="false" placeholder="Search duration" autocomplete="off"></div>
                <input type="hidden" name="duration">
                <div class="nexa-duration-options" id="nexa-account-duration-options" role="listbox" data-nexa-duration-options hidden>${this.accountDurationOptions().map(([minutes, label], index) => `<button id="nexa-account-duration-option-${index}" type="button" role="option" aria-selected="false" data-nexa-duration-option data-value="${minutes}" data-label="${this.escape(label)}" data-search="${this.escape(label.toLowerCase())}">${this.escape(label)}</button>`).join('')}</div>
            </div></div>`;
    }

    mountAccountDurationPicker(overlay) {
        const picker = overlay.querySelector('[data-nexa-duration-picker]');
        if (!picker) return;
        const input = picker.querySelector('[data-nexa-duration-search]');
        const list = picker.querySelector('[data-nexa-duration-options]');
        const options = [...picker.querySelectorAll('[data-nexa-duration-option]')];
        let activeIndex = -1;
        const visibleOptions = () => options.filter(option => !option.hidden);
        const open = () => { list.hidden = false; input.setAttribute('aria-expanded', 'true'); };
        const close = () => {
            list.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            activeIndex = -1;
            options.forEach(option => option.classList.remove('is-active'));
        };
        const select = option => {
            if (!option) return;
            picker.querySelector('[name="duration"]').value = option.dataset.value;
            input.value = option.dataset.label;
            input.dataset.selectedValue = option.dataset.value;
            options.forEach(item => item.setAttribute('aria-selected', String(item === option)));
            close();
        };
        const filter = () => {
            const term = input.value.trim().toLowerCase();
            if (input.value !== options.find(option => option.dataset.value === input.dataset.selectedValue)?.dataset.label) {
                picker.querySelector('[name="duration"]').value = '';
                input.dataset.selectedValue = '';
            }
            options.forEach(option => option.hidden = Boolean(term) && !option.dataset.search.includes(term));
            activeIndex = -1;
            open();
        };
        input.addEventListener('focus', open);
        input.addEventListener('input', filter);
        input.addEventListener('keydown', event => {
            const visible = visibleOptions();
            if (event.key === 'Escape') { event.preventDefault(); close(); return; }
            if (!['ArrowDown', 'ArrowUp', 'Enter'].includes(event.key) || !visible.length) return;
            event.preventDefault();
            if (event.key === 'Enter') { select(visible[activeIndex >= 0 ? activeIndex : 0]); return; }
            const offset = event.key === 'ArrowDown' ? 1 : -1;
            activeIndex = (activeIndex + offset + visible.length) % visible.length;
            visible.forEach((option, index) => option.classList.toggle('is-active', index === activeIndex));
            input.setAttribute('aria-activedescendant', visible[activeIndex].id);
            visible[activeIndex].scrollIntoView({block: 'nearest'});
        });
        options.forEach(option => option.addEventListener('mousedown', event => { event.preventDefault(); select(option); }));
        picker.addEventListener('focusout', () => window.setTimeout(() => {
            if (!picker.contains(document.activeElement)) close();
        }, 0));
    }

    async openAccountInteractionDialog(channelKey, returnFocus = null) {
        if (!this.getAcl().checkScope('Note', 'create')) {
            Espo.Ui.error('You do not have permission to log company interactions.');
            return;
        }
        this.closeAccountInteractionDialog();
        const channel = this.accountInteractionChannels()[channelKey] || 'Interaction';
        const isCall = channelKey === 'call';
        const isMeeting = channelKey === 'meeting';
        if (!Array.isArray(this.accountContacts)) {
            try {
                this.accountContacts = await this.loadAccountContacts();
            } catch (error) {
                this.accountContacts = [];
            }
        }
        const interactionContacts = this.accountContacts || [];
        const now = new Date();
        const localDate = new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
        const overlay = document.createElement('div');
        overlay.className = 'nexa-interaction-overlay';
        overlay.dataset.nexaAccountInteractionDialog = 'true';
        overlay.innerHTML = `<section class="nexa-interaction-dialog" role="dialog" aria-modal="true" aria-labelledby="nexa-account-interaction-title">
            <header><div><p>Company activity</p><h2 id="nexa-account-interaction-title">Log ${channel}</h2></div><button type="button" class="nexa-dialog-close" data-close aria-label="Close"><span class="fas fa-times" aria-hidden="true"></span></button></header>
            <form><p class="nexa-interaction-help">Record a completed interaction on this company's activity timeline.</p>
                ${this.accountSmsContactPicker(interactionContacts, isMeeting ? 'Attendees' : 'Contacted')}
                ${isMeeting
                    ? `<div class="nexa-interaction-grid"><label><span>Meeting outcome</span><select class="form-control" name="meetingOutcome"><option value="">Not specified</option>${this.accountMeetingOutcomeOptions().map(value => `<option>${this.escape(value)}</option>`).join('')}</select></label><label><span>Meeting start time</span><input class="form-control" type="datetime-local" name="occurredAt" value="${localDate}" required></label></div>${this.accountDurationPickerHtml()}`
                    : isCall
                        ? `<div class="nexa-interaction-grid"><label><span>Call outcome</span><select class="form-control" name="callOutcome"><option value="">Not specified</option>${this.accountCallOutcomeOptions().map(value => `<option>${this.escape(value)}</option>`).join('')}</select></label><label><span>Call direction</span><select class="form-control" name="direction"><option value="Inbound">Inbound</option><option value="Outbound">Outbound</option></select></label></div><label><span>Activity date and time</span><input class="form-control" type="datetime-local" name="occurredAt" value="${localDate}" required></label>`
                        : `<label><span>Activity date and time</span><input class="form-control" type="datetime-local" name="occurredAt" value="${localDate}" required></label>`}
                <label><span>Notes</span><div class="nexa-native-rich-editor nexa-interaction-rich-editor" data-nexa-account-interaction-notes-editor><div class="nexa-note-editor-loading"><span class="fas fa-circle-notch fa-spin" aria-hidden="true"></span><span>Loading editor</span></div></div></label>
                <p class="nexa-interaction-error" data-error role="alert" hidden></p>
                <footer><button type="button" class="btn btn-default" data-close>Cancel</button><button type="submit" class="btn btn-primary" data-save><span class="fas fa-check" aria-hidden="true"></span><span>Log interaction</span></button></footer>
            </form></section>`;
        document.body.append(overlay);
        this.accountInteractionDialog = overlay;
        this.accountInteractionReturnFocus = returnFocus;
        this.mountAccountSmsContactPicker(overlay);
        if (isMeeting) this.mountAccountDurationPicker(overlay);
        overlay.querySelectorAll('[data-close]').forEach(button => button.addEventListener('click', () => this.closeAccountInteractionDialog()));
        overlay.addEventListener('mousedown', event => { if (event.target === overlay) this.closeAccountInteractionDialog(); });
        overlay.addEventListener('keydown', event => this.trapAccountDialogKeys(event, () => this.closeAccountInteractionDialog()));
        overlay.querySelector('form').addEventListener('submit', event => {
            event.preventDefault();
            const formData = new FormData(event.currentTarget);
            if (this.accountInteractionNotesEditor) {
                this.accountInteractionNotesEditor.fetchToModel();
                formData.set('notes', String(this.accountInteractionNotesModel.get('post') || '').trim());
            }
            this.saveAccountInteraction(channelKey, channel, formData);
        });
        try {
            this.accountInteractionNotesModel = await this.getModelFactory().create('Note');
            if (!overlay.isConnected) return;
            this.accountInteractionNotesEditor = await this.createView(
                'nexaAccountInteractionNotesEditor',
                'custom:views/fields/nexa-rich-text',
                {
                    fullSelector: '[data-nexa-account-interaction-notes-editor]',
                    model: this.accountInteractionNotesModel,
                    name: 'post',
                    mode: 'edit',
                    params: {height: 190, minHeight: 150},
                }
            );
            await this.accountInteractionNotesEditor.render();
        } catch (error) {
            const message = overlay.querySelector('[data-error]');
            message.textContent = 'The rich-text notes editor could not be loaded.';
            message.hidden = false;
        }
        window.setTimeout(() => overlay.querySelector('[data-nexa-sms-contact-search]')?.focus(), 0);
    }

    accountSmsContactPicker(contacts, label = 'Contacted') {
        const options = contacts.map((contact, index) => {
            const email = String(contact.emailAddress || '').trim();
            const phone = String(contact.phoneNumber || '').trim();
            const name = String(contact.name || 'Unnamed contact').trim();
            const detail = email || phone || 'No email or phone recorded';
            const search = `${name} ${email} ${phone}`.toLowerCase();
            return `<button id="nexa-account-sms-contact-option-${index}" type="button" role="option" aria-selected="false" data-nexa-sms-contact-option data-id="${this.escape(contact.id)}" data-name="${this.escape(name)}" data-email="${this.escape(email)}" data-search="${this.escape(search)}"><strong>${this.escape(name)}</strong><small>${this.escape(detail)}</small></button>`;
        }).join('');
        return `<div class="nexa-sms-contacted-field"><label for="nexa-account-sms-contact-search">${this.escape(label)}</label>
            <div class="nexa-sms-contact-picker" data-nexa-sms-contact-picker>
                <div class="nexa-sms-contact-input"><span class="fas fa-search" aria-hidden="true"></span><input id="nexa-account-sms-contact-search" type="search" class="form-control" data-nexa-sms-contact-search role="combobox" aria-autocomplete="list" aria-controls="nexa-account-sms-contact-options" aria-expanded="false" placeholder="Search associated contacts" autocomplete="off"></div>
                <input type="hidden" name="contactedContactId"><input type="hidden" name="contactedEmail"><input type="hidden" name="contactedName">
                <div class="nexa-sms-contact-options" id="nexa-account-sms-contact-options" role="listbox" data-nexa-sms-contact-options hidden>${options || '<p>No associated Contacts are available.</p>'}</div>
            </div></div>`;
    }

    mountAccountSmsContactPicker(overlay) {
        const picker = overlay.querySelector('[data-nexa-sms-contact-picker]');
        if (!picker) return;
        const input = picker.querySelector('[data-nexa-sms-contact-search]');
        const list = picker.querySelector('[data-nexa-sms-contact-options]');
        const options = [...picker.querySelectorAll('[data-nexa-sms-contact-option]')];
        let activeIndex = -1;
        const visibleOptions = () => options.filter(option => !option.hidden);
        const open = () => {
            list.hidden = false;
            input.setAttribute('aria-expanded', 'true');
        };
        const close = () => {
            list.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            activeIndex = -1;
            options.forEach(option => option.classList.remove('is-active'));
        };
        const filter = () => {
            const term = input.value.trim().toLowerCase();
            options.forEach(option => option.hidden = Boolean(term) && !option.dataset.search.includes(term));
            list.querySelector('[data-nexa-sms-contact-empty]')?.remove();
            if (options.length && !visibleOptions().length) {
                const empty = document.createElement('p');
                empty.dataset.nexaSmsContactEmpty = 'true';
                empty.textContent = 'No matching contact email.';
                list.append(empty);
            }
            activeIndex = -1;
            open();
        };
        const select = option => {
            if (!option) return;
            picker.querySelector('[name="contactedContactId"]').value = option.dataset.id;
            picker.querySelector('[name="contactedEmail"]').value = option.dataset.email;
            picker.querySelector('[name="contactedName"]').value = option.dataset.name;
            input.value = option.dataset.name;
            input.dataset.selectedId = option.dataset.id;
            input.setAttribute('aria-activedescendant', '');
            options.forEach(item => item.setAttribute('aria-selected', String(item === option)));
            close();
        };
        input.addEventListener('focus', open);
        input.addEventListener('input', () => {
            if (input.value !== options.find(option => option.dataset.id === input.dataset.selectedId)?.dataset.name) {
                picker.querySelector('[name="contactedContactId"]').value = '';
                picker.querySelector('[name="contactedEmail"]').value = '';
                picker.querySelector('[name="contactedName"]').value = '';
                input.dataset.selectedId = '';
            }
            filter();
        });
        input.addEventListener('keydown', event => {
            const visible = visibleOptions();
            if (event.key === 'Escape') { event.preventDefault(); close(); return; }
            if (!['ArrowDown', 'ArrowUp', 'Enter'].includes(event.key) || !visible.length) return;
            event.preventDefault();
            if (event.key === 'Enter') { select(visible[activeIndex >= 0 ? activeIndex : 0]); return; }
            const offset = event.key === 'ArrowDown' ? 1 : -1;
            activeIndex = (activeIndex + offset + visible.length) % visible.length;
            visible.forEach((option, index) => option.classList.toggle('is-active', index === activeIndex));
            input.setAttribute('aria-activedescendant', visible[activeIndex].id);
            visible[activeIndex].scrollIntoView({block: 'nearest'});
        });
        options.forEach(option => option.addEventListener('mousedown', event => {
            event.preventDefault();
            select(option);
        }));
        picker.addEventListener('focusout', () => window.setTimeout(() => {
            if (!picker.contains(document.activeElement)) close();
        }, 0));
    }

    async saveAccountInteraction(channelKey, channel, data) {
        const dialog = this.accountInteractionDialog;
        if (!dialog || this.accountInteractionSavePending) return;
        const occurredAt = String(data.get('occurredAt') || '').trim();
        const error = dialog.querySelector('[data-error]');
        const isCall = channelKey === 'call';
        const isMeeting = channelKey === 'meeting';
        const contactedId = String(data.get('contactedContactId') || '').trim();
        const contactedEmail = String(data.get('contactedEmail') || '').trim();
        if (!occurredAt || !contactedId) {
            error.textContent = isMeeting
                ? 'Select an attendee and enter the meeting start time.'
                : 'Select a Contact and enter the interaction date and time.';
            error.hidden = false;
            return;
        }
        const direction = String(data.get('direction') || 'Outbound');
        const notes = String(data.get('notes') || '').trim();
        const contactedName = String(data.get('contactedName') || '').trim();
        const contacted = contactedEmail ? `${contactedEmail}${contactedName ? ` (${contactedName})` : ''}` : contactedName;
        const lines = isMeeting
            ? [`[${channel}]`, `Attendees: ${contacted}`, data.get('meetingOutcome') ? `Meeting outcome: ${data.get('meetingOutcome')}` : '', `Start: ${occurredAt.replace('T', ' ')}`, data.get('duration') ? `Duration: ${this.accountDurationOptions().find(([minutes]) => String(minutes) === String(data.get('duration')))?.[1] || ''}` : '', notes ? `\n${notes}` : ''].filter(Boolean)
            : isCall
                ? [`[${channel} - ${direction}]`, `Contacted: ${contacted}`, data.get('callOutcome') ? `Call outcome: ${data.get('callOutcome')}` : '', `Activity date: ${occurredAt.replace('T', ' ')}`, notes ? `\n${notes}` : ''].filter(Boolean)
                : [`[${channel}]`, `Contacted: ${contacted}`, `Occurred: ${occurredAt.replace('T', ' ')}`, notes ? `\n${notes}` : ''].filter(Boolean);
        const submit = dialog.querySelector('[data-save]');
        this.accountInteractionSavePending = true;
        submit.disabled = true;
        submit.classList.add('is-loading');
        try {
            const note = await this.getModelFactory().create('Note');
            note.set({type: 'Post', post: lines.join('\n'), parentType: 'Account', parentId: this.model.id});
            await note.save(null);
            this.closeAccountInteractionDialog();
            Espo.Ui.success(`${channel} logged`);
            await this.refreshAccountEngagement(['activity'], 'activity');
        } catch (saveError) {
            error.textContent = 'The interaction could not be saved. Check your access and try again.';
            error.hidden = false;
            submit.disabled = false;
            submit.classList.remove('is-loading');
        } finally {
            this.accountInteractionSavePending = false;
        }
    }

    closeAccountInteractionDialog() {
        const returnFocus = this.accountInteractionReturnFocus;
        if (this.getView('nexaAccountInteractionNotesEditor')) {
            this.clearView('nexaAccountInteractionNotesEditor');
        }
        this.accountInteractionDialog?.remove();
        this.accountInteractionDialog = null;
        this.accountInteractionReturnFocus = null;
        this.accountInteractionNotesEditor = null;
        this.accountInteractionNotesModel = null;
        this.accountInteractionSavePending = false;
        returnFocus?.focus?.();
    }

    trapAccountDialogKeys(event, close) {
        if (event.key === 'Escape') {
            event.preventDefault();
            close();
            return;
        }
        if (event.key !== 'Tab') return;
        const controls = [...event.currentTarget.querySelectorAll('button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [contenteditable="true"]')];
        if (!controls.length) return;
        const first = controls[0];
        const last = controls.at(-1);
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    }

    async refreshAccountEngagement(keys, selectedTab = null) {
        const shell = this.element.querySelector('[data-nexa-company-workspace]');
        if (!shell) return;
        await Promise.all(keys.map(key => this.loadTimelinePage(shell, key, false)));
        if (selectedTab) shell.querySelector(`[data-nexa-company-tab="${selectedTab}"]`)?.click();
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
        shell.addEventListener('submit', event => this.handleEngagementCommentSubmit(event, shell));
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
        this.renderCompanyAvatarFallback(shell, name);
        shell.querySelector('[data-nexa-company-subtitle]').textContent = `${industry} - ${location}`;
        this.loadCompanyAvatar(shell);

        this.setFact(shell, 'website', this.linkValue(this.model.get('website')));
        this.setFact(shell, 'emailAddress', this.emailValue(this.model.get('emailAddress')));
        this.setFact(shell, 'phoneNumber', this.model.get('phoneNumber') || 'Not recorded');
        this.setFact(shell, 'type', this.model.get('type') || 'Not recorded');
        this.setFact(shell, 'industry', industry);
        this.setFact(shell, 'annualRevenue', this.formatAnnualRevenue());
        this.setFact(shell, 'numberOfEmployees', this.formatCount(this.model.get('numberOfEmployees')));
        this.setFact(shell, 'leadScore', this.formatLeadScore());
        this.setFact(shell, 'lifecycleStage', this.companyLifecycleBadge());
        this.setFact(shell, 'leadStatus', this.companyLeadStatusBadge());
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

    renderCompanyAvatarFallback(shell, name) {
        const avatar = shell.querySelector('.nexa-company-avatar');
        if (!avatar) return;

        if (this.companyAvatarUrl) {
            this.renderCompanyAvatarImage(avatar, this.companyAvatarUrl);
            return;
        }

        avatar.textContent = name.trim().charAt(0).toUpperCase() || '?';
        avatar.classList.remove('nexa-company-avatar--image');
    }

    async loadCompanyAvatar(shell) {
        if (!this.model.id || (!this.model.get('companyLogoId') && !this.model.get('website'))) return;

        const signature = `${this.model.id}:${this.model.get('companyLogoId') || ''}:${this.model.get('website') || ''}`;
        if (signature === this.companyAvatarSignature && (this.companyAvatarUrl || this.companyAvatarPending)) return;

        this.companyAvatarSignature = signature;
        this.companyAvatarPending = true;

        try {
            const payload = await Espo.Ajax.getRequest(`Nexa/account/${encodeURIComponent(this.model.id)}/avatar`);
            if (this.companyAvatarSignature !== signature || !payload?.available || !payload.data || !payload.mimeType) return;

            const binary = atob(payload.data);
            const bytes = new Uint8Array(binary.length);
            for (let index = 0; index < binary.length; index++) bytes[index] = binary.charCodeAt(index);
            const url = URL.createObjectURL(new Blob([bytes], {type: payload.mimeType}));

            if (this.companyAvatarSignature !== signature) {
                URL.revokeObjectURL(url);
                return;
            }

            this.releaseCompanyAvatar(false);
            this.companyAvatarUrl = url;
            const avatar = shell.querySelector('.nexa-company-avatar');
            if (avatar) this.renderCompanyAvatarImage(avatar, url);
        } catch (error) {
            // The company initial remains the stable offline/error fallback.
        } finally {
            if (this.companyAvatarSignature === signature) this.companyAvatarPending = false;
        }
    }

    renderCompanyAvatarImage(avatar, url) {
        const image = document.createElement('img');
        image.src = url;
        image.alt = '';
        image.className = 'nexa-company-avatar-image';
        avatar.replaceChildren(image);
        avatar.classList.add('nexa-company-avatar--image');
    }

    releaseCompanyAvatar(clearSignature = true) {
        if (this.companyAvatarUrl) URL.revokeObjectURL(this.companyAvatarUrl);
        this.companyAvatarUrl = null;
        if (clearSignature) this.companyAvatarSignature = null;
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
            lifecycleStage: {type: 'enum'},
            leadStatus: {type: 'enum'},
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

    companyLifecycleBadge() {
        const value = this.model.get('lifecycleStage');
        const classes = {
            Subscriber: 'subscriber', Lead: 'lead', MarketingQualifiedLead: 'mql',
            SalesQualifiedLead: 'sql', Opportunity: 'opportunity', Customer: 'customer',
            Evangelist: 'evangelist', Other: 'other',
        };

        return this.companyEnumBadge('lifecycleStage', value, 'nexa-lifecycle-stage', classes[value] || 'other');
    }

    companyLeadStatusBadge() {
        const value = this.model.get('leadStatus');
        const classes = {
            New: 'new', Open: 'open', InProgress: 'in-progress', OpenDeal: 'open-deal',
            Unqualified: 'unqualified', AttemptedToContact: 'attempted',
            Connected: 'connected', BadTiming: 'bad-timing',
        };

        return this.companyEnumBadge('leadStatus', value, 'nexa-lead-status', classes[value] || 'other');
    }

    companyEnumBadge(field, value, className, modifier) {
        if (!value) return 'Not recorded';
        const badge = document.createElement('span');
        badge.className = `${className} ${className}--${modifier}`;
        badge.textContent = this.getLanguage().translateOption(value, field, 'Account') || value;
        return badge;
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
        this.accountEngagementComments = new Map();
        try {
            const contacts = await this.loadAccountContacts();
            this.accountContacts = contacts;
            this.renderContactRail(shell);
            this.accountContactMap = new Map(contacts.map(contact => [contact.id, contact]));
            this.accountContactEmailMap = new Map(contacts
                .filter(contact => contact.emailAddress)
                .map(contact => [String(contact.emailAddress).toLowerCase(), contact]));
            this.collapsedAccountEngagementIds = this.collapsedAccountEngagementIds || new Set();
            this.knownAccountEngagementIds = this.knownAccountEngagementIds || new Set();
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
            if (Array.isArray(payload?.comments)) this.indexAccountEngagementComments(payload.comments);
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
            usable.forEach(record => {
                const recordKey = this.engagementKey(record);
                if (this.knownAccountEngagementIds.has(recordKey)) return;
                this.knownAccountEngagementIds.add(recordKey);
                this.collapsedAccountEngagementIds.add(recordKey);
            });
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
        return /^<!-- nexa-(?:note-(?:comment|reply)|engagement-(?:comment|reply)):/i.test(String(record.post || '').trim());
    }

    indexAccountEngagementComments(records) {
        const comments = new Map();
        const replies = new Map();
        records.forEach(record => {
            const post = String(record.post || '');
            const reply = post.match(/^<!-- nexa-engagement-reply:([A-Za-z0-9_-]+) -->\s*\n?/i);
            if (reply) {
                const list = replies.get(reply[1]) || [];
                list.push({...record, content: post.replace(reply[0], '')});
                replies.set(reply[1], list);
                return;
            }
            const comment = post.match(/^<!-- nexa-engagement-comment:([A-Za-z]+):([A-Za-z0-9_-]+) -->\s*\n?/i);
            if (!comment) return;
            const key = `${comment[1]}:${comment[2]}`;
            const list = comments.get(key) || [];
            list.push({...record, content: post.replace(comment[0], '')});
            comments.set(key, list);
        });
        comments.forEach(list => list.forEach(comment => comment.replies = replies.get(comment.id) || []));
        this.accountEngagementComments = comments;
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
        item.querySelector('[data-subtitle]').textContent = this.isLoggedInteraction(record) ? `by ${owner}` : `${record._entityType} by ${owner}`;
        item.querySelector('[data-contact]').textContent = contactName;
        item.querySelector('[data-owner]').textContent = owner;
        item.querySelector('[data-status]').textContent = record.status || record._entityType;
        item.querySelector('time').textContent = this.engagementDate(record);
        const preview = this.engagementPreview(record);
        item.querySelector('.nexa-activity-preview').textContent = preview;
        const richContent = this.engagementRichHtml(record);
        const expandedContent = item.querySelector('.nexa-company-engagement-preview');
        if (richContent) expandedContent.innerHTML = richContent;
        else expandedContent.textContent = preview;
        item.querySelector('.nexa-activity-details').insertAdjacentHTML('beforeend', this.accountEngagementCommentsSection(record));
        TenantImages.hydrate(item);
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

        const commentToggle = event.target.closest('[data-nexa-company-comment-toggle]');
        if (commentToggle) {
            const form = card.querySelector('[data-nexa-company-comment-form]');
            form.hidden = false;
            this.mountAccountCommentEditor(this.engagementKey(record), form);
            return;
        }
        const commentCancel = event.target.closest('[data-nexa-company-comment-cancel]');
        if (commentCancel) {
            const form = commentCancel.closest('[data-nexa-company-comment-form]');
            form.hidden = true;
            this.clearAccountCommentEditor(this.engagementKey(record));
            return;
        }
        if (event.target.closest('[data-nexa-company-comments-toggle]')) {
            const key = this.engagementKey(record);
            this.accountCommentsHiddenIds = this.accountCommentsHiddenIds || new Set();
            if (this.accountCommentsHiddenIds.has(key)) this.accountCommentsHiddenIds.delete(key);
            else this.accountCommentsHiddenIds.add(key);
            this.renderAllEngagementLists(shell);
            return;
        }
        const replyToggle = event.target.closest('[data-nexa-company-reply-toggle]');
        if (replyToggle) {
            const comment = replyToggle.closest('[data-nexa-company-comment-id]');
            const form = comment.querySelector('[data-nexa-company-reply-form]');
            form.hidden = false;
            this.mountAccountCommentEditor(`reply-${comment.dataset.nexaCompanyCommentId}`, form);
            return;
        }
        const replyCancel = event.target.closest('[data-nexa-company-reply-cancel]');
        if (replyCancel) {
            const comment = replyCancel.closest('[data-nexa-company-comment-id]');
            replyCancel.closest('[data-nexa-company-reply-form]').hidden = true;
            this.clearAccountCommentEditor(`reply-${comment.dataset.nexaCompanyCommentId}`);
            return;
        }
        if (event.target.closest('[data-nexa-company-replies-toggle]')) {
            const commentId = event.target.closest('[data-nexa-company-comment-id]').dataset.nexaCompanyCommentId;
            this.accountRepliesVisibleIds = this.accountRepliesVisibleIds || new Set();
            if (this.accountRepliesVisibleIds.has(commentId)) this.accountRepliesVisibleIds.delete(commentId);
            else this.accountRepliesVisibleIds.add(commentId);
            this.renderAllEngagementLists(shell);
            return;
        }

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
        if (this.isLoggedInteraction(record)) {
            const match = String(record.post || '').trim().match(/^\[([^\]]+?)(?: - (?:Outbound|Inbound))?\]/i);
            return `Logged ${match?.[1] || 'interaction'}`;
        }
        if (record._entityType === 'Note') return this.engagementPreview(record) || 'Contact note';
        return record.name || record.subject || `${record._entityType} record`;
    }

    engagementRichHtml(record) {
        if (record._entityType !== 'Note') return '';
        let value = String(record.post || '');
        if (this.isLoggedInteraction(record)) {
            const boundary = value.indexOf('\n\n');
            value = boundary >= 0 ? value.slice(boundary + 2) : '';
        }
        return value ? this.getHelper().sanitizeHtml(value) : '';
    }

    accountEngagementCommentsSection(record) {
        const key = this.engagementKey(record);
        const comments = this.accountEngagementComments?.get(key) || [];
        const visible = comments.length > 0 && this.accountCommentsHiddenIds?.has(key) !== true;
        const canComment = this.getAcl().checkScope('Note', 'create') === true;
        return `<footer class="nexa-company-comment-footer">
                ${canComment ? '<button type="button" data-nexa-company-comment-toggle><span class="far fa-comment" aria-hidden="true"></span><span>Add comment</span></button>' : ''}
                ${comments.length ? `<button type="button" class="nexa-task-reply-toggle" data-nexa-company-comments-toggle>${visible ? 'Hide comments' : `Show comments (${comments.length})`}</button>` : '<span>0 comments</span>'}
            </footer>
            <div class="nexa-note-comments"${visible ? '' : ' hidden'}>${comments.map(comment => this.accountEngagementComment(comment)).join('')}</div>
            <form class="nexa-note-comment-form" data-nexa-company-comment-form hidden>
                <div class="nexa-native-rich-editor nexa-comment-editor" data-nexa-account-comment-editor="${this.escape(key)}" aria-label="Comment"><div class="nexa-note-editor-loading"><span class="fas fa-circle-notch fa-spin" aria-hidden="true"></span><span>Loading editor</span></div></div>
                <div><button type="button" class="btn btn-default btn-xs" data-nexa-company-comment-cancel>Cancel</button><button type="submit" class="btn btn-primary btn-xs">Comment</button></div>
                <p role="alert" data-nexa-company-comment-error hidden></p>
            </form>`;
    }

    accountEngagementComment(comment) {
        const replies = comment.replies || [];
        const visible = this.accountRepliesVisibleIds?.has(comment.id) === true;
        const canComment = this.getAcl().checkScope('Note', 'create') === true;
        const author = comment.createdByName || 'Team member';
        const date = comment.createdAt ? this.getDateTime().toDisplay(comment.createdAt) : '';
        return `<div class="nexa-note-comment" data-nexa-company-comment-id="${this.escape(comment.id)}">
            <div><strong>${this.escape(author)}</strong><time>${this.escape(date)}</time></div><p>${this.formatAccountComment(comment.content)}</p>
            <div class="nexa-task-comment-actions">${canComment ? '<button type="button" class="nexa-task-reply-toggle" data-nexa-company-reply-toggle>Reply</button>' : ''}${replies.length ? `<button type="button" class="nexa-task-reply-toggle" data-nexa-company-replies-toggle>${visible ? 'Hide replies' : `Show replies (${replies.length})`}</button>` : ''}</div>
            ${replies.length ? `<div class="nexa-task-comment-replies"${visible ? '' : ' hidden'}>${replies.map(reply => this.accountEngagementReply(reply)).join('')}</div>` : ''}
            <form class="nexa-note-comment-form nexa-task-reply-form" data-nexa-company-reply-form hidden><div class="nexa-native-rich-editor nexa-comment-editor" data-nexa-account-comment-editor="reply-${this.escape(comment.id)}" aria-label="Reply"><div class="nexa-note-editor-loading"><span class="fas fa-circle-notch fa-spin" aria-hidden="true"></span><span>Loading editor</span></div></div><div><button type="button" class="btn btn-default btn-xs" data-nexa-company-reply-cancel>Cancel</button><button type="submit" class="btn btn-primary btn-xs">Reply</button></div><p role="alert" data-nexa-company-comment-error hidden></p></form>
        </div>`;
    }

    accountEngagementReply(reply) {
        const author = reply.createdByName || 'Team member';
        const date = reply.createdAt ? this.getDateTime().toDisplay(reply.createdAt) : '';
        return `<div class="nexa-note-comment nexa-task-comment-reply"><div><strong>${this.escape(author)}</strong><time>${this.escape(date)}</time></div><p>${this.formatAccountComment(reply.content)}</p></div>`;
    }

    formatAccountComment(content) {
        return this.getHelper().sanitizeHtml(String(content || ''));
    }

    async mountAccountCommentEditor(editorId, form) {
        this.accountCommentEditors = this.accountCommentEditors || new Map();
        if (this.accountCommentEditors.has(editorId)) return;
        const key = `nexaAccountComment-${editorId.replace(/[^A-Za-z0-9_-]/g, '-')}`;
        const model = await this.getModelFactory().create('Note');
        if (!form.isConnected || form.hidden) return;
        const view = await this.createView(key, 'custom:views/fields/nexa-rich-text', {
            fullSelector: `[data-nexa-account-comment-editor="${editorId}"]`, model, name: 'post', mode: 'edit',
            params: {height: 150, minHeight: 120},
        });
        await view.render();
        this.accountCommentEditors.set(editorId, {key, model, view});
        form.querySelector('.note-editable')?.focus();
    }

    clearAccountCommentEditor(editorId) {
        const entry = this.accountCommentEditors?.get(editorId);
        if (!entry) return;
        if (this.getView(entry.key)) this.clearView(entry.key);
        this.accountCommentEditors.delete(editorId);
    }

    clearAccountCommentEditors() {
        [...(this.accountCommentEditors?.entries() || [])].forEach(([id]) => this.clearAccountCommentEditor(id));
    }

    async handleEngagementCommentSubmit(event, shell) {
        const form = event.target.closest('[data-nexa-company-comment-form], [data-nexa-company-reply-form]');
        if (!form) return;
        event.preventDefault();
        const card = form.closest('[data-nexa-company-engagement-id]');
        const targetKey = `${card.dataset.nexaCompanyEngagementType}:${card.dataset.nexaCompanyEngagementId}`;
        const commentNode = form.closest('[data-nexa-company-comment-id]');
        const editorId = commentNode ? `reply-${commentNode.dataset.nexaCompanyCommentId}` : targetKey;
        const editor = this.accountCommentEditors?.get(editorId);
        if (!editor || form.dataset.saving === 'true') return;
        editor.view.fetchToModel();
        const content = String(editor.model.get('post') || '').trim();
        const error = form.querySelector('[data-nexa-company-comment-error]');
        const plain = document.createElement('div');
        plain.innerHTML = this.getHelper().sanitizeHtml(content);
        if (!plain.textContent.trim() && !plain.querySelector('img, table, hr')) {
            error.textContent = `Enter a ${commentNode ? 'reply' : 'comment'} before saving.`;
            error.hidden = false;
            return;
        }
        form.dataset.saving = 'true';
        form.querySelector('button[type="submit"]').disabled = true;
        try {
            const marker = commentNode
                ? `<!-- nexa-engagement-reply:${commentNode.dataset.nexaCompanyCommentId} -->`
                : `<!-- nexa-engagement-comment:${targetKey} -->`;
            const note = await this.getModelFactory().create('Note');
            note.set({type: 'Post', post: `${marker}\n${content}`, parentType: 'Account', parentId: this.model.id});
            await note.save(null);
            this.clearAccountCommentEditor(editorId);
            this.accountCommentsHiddenIds?.delete(targetKey);
            if (commentNode) {
                this.accountRepliesVisibleIds = this.accountRepliesVisibleIds || new Set();
                this.accountRepliesVisibleIds.add(commentNode.dataset.nexaCompanyCommentId);
            }
            await this.loadTimelinePage(shell, 'activity', false);
            this.renderAllEngagementLists(shell);
            Espo.Ui.success(commentNode ? 'Reply added' : 'Comment added');
        } catch (saveError) {
            error.textContent = `The ${commentNode ? 'reply' : 'comment'} could not be saved.`;
            error.hidden = false;
            form.dataset.saving = 'false';
            form.querySelector('button[type="submit"]').disabled = false;
        }
    }

    engagementPreview(record) {
        let value = record.post || record.description || record.subject || '';
        if (this.isLoggedInteraction(record)) {
            const lines = String(value).split('\n');
            const subject = String(lines.shift() || '').replace(/^\[[^\]]+]\s*/, '').trim();
            const boundary = lines.findIndex(line => line.trim() === '');
            const notes = boundary >= 0 ? lines.slice(boundary + 1).join('\n') : '';
            value = [subject, notes].filter(Boolean).join(' ');
        }
        const container = document.createElement('div');
        container.innerHTML = this.getHelper().sanitizeHtml(String(value));
        return container.textContent.replace(/\s+/g, ' ').trim().slice(0, 180);
    }

    engagementSearchText(record) {
        return (`${this.engagementTitle(record)} ${this.engagementPreview(record)} ${record.status || ''} ` +
            `${record.assignedUserName || record.createdByName || ''} ${record._contact?.name || ''}`).toLowerCase();
    }

    loggedInteractionDate(record) {
        if (!this.isLoggedInteraction(record)) return '';
        const match = String(record.post || '').match(/^(?:Occurred|Activity date|Start):\s*(.+)$/mi);
        return match?.[1]?.trim() || '';
    }

    engagementTimestamp(record) {
        const interactionDate = this.loggedInteractionDate(record);
        if (interactionDate) {
            const localTime = Date.parse(interactionDate.replace(' ', 'T'));
            if (!Number.isNaN(localTime)) return localTime;
        }
        const value = record.dateStart || record.dateSent || record.sendAt || record.dateEnd || record.createdAt;
        const time = Date.parse(String(value || '').replace(' ', 'T') + 'Z');
        return Number.isNaN(time) ? 0 : time;
    }

    engagementDate(record) {
        const interactionDate = this.loggedInteractionDate(record);
        if (interactionDate) {
            const localDate = new Date(interactionDate.replace(' ', 'T'));
            if (!Number.isNaN(localDate.getTime())) return new Intl.DateTimeFormat(undefined, {
                day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
            }).format(localDate);
        }
        const value = record.dateStart || record.dateSent || record.sendAt || record.dateEnd || record.createdAt;
        if (!value) return 'Date not recorded';
        const date = new Date(String(value).replace(' ', 'T') + 'Z');
        return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat(undefined, {
            day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
        }).format(date);
    }

    escape(value) {
        const node = document.createElement('span');
        node.textContent = String(value ?? '');
        return node.innerHTML;
    }

    engagementEmpty(message, icon) {
        const empty = document.createElement('div');
        empty.className = 'nexa-company-engagement-empty';
        empty.innerHTML = `<span class="fas ${icon}" aria-hidden="true"></span><span></span>`;
        empty.querySelector('span:last-child').textContent = message;
        return empty;
    }
});
