define('custom:views/contact/record/detail-workspace', ['crm:views/contact/record/detail'], Dep => {
    return class extends Dep {
        setup() {
            super.setup();
            document.body.classList.add('nexa-contact-detail-page');
            this.once('remove', () => {
                document.body.classList.remove('nexa-contact-detail-page');
                this.cancelInlineDetailEdit();
                this.closeCustomerCommandMenu();
                this.closeInteractionDialog();
                this.closeNoteDialog();
                this.clearContactCommentEditors();
                this.releaseProfileImage();
                if (this.actionMenuDocumentHandler) {
                    document.removeEventListener('click', this.actionMenuDocumentHandler);
                }
                if (this.notesFilterDocumentHandler) {
                    document.removeEventListener('click', this.notesFilterDocumentHandler);
                }
                if (this.noteActionsDocumentHandler) {
                    document.removeEventListener('click', this.noteActionsDocumentHandler);
                }
                this.closeNoteDeleteDialog();
            });
        }

        afterRender() {
            super.afterRender();
            this.prepareCustomerWorkspace();
        }

        async prepareCustomerWorkspace() {
            if (this.workspaceFetchPending) return;

            const root = this.element;
            root?.classList.add('nexa-contact-detail-workspace', 'nexa-customer-workspace-loading');
            root?.setAttribute('aria-busy', 'true');

            const existing = root?.querySelector('[data-nexa-contact-workspace]');
            const detachedNativeRecord = root?.querySelector(':scope > .detail');
            if (existing && detachedNativeRecord) {
                this.placeNativePanels(detachedNativeRecord, existing);
                this.finishWorkspaceLoading(root);
                return;
            }
            if (existing) {
                this.finishWorkspaceLoading(root);
                return;
            }

            this.workspaceFetchPending = true;
            try {
                await this.model.fetch();
            } catch (error) {
                // The native detail remains available if a refresh request fails.
            } finally {
                this.workspaceFetchPending = false;
            }

            if (this.isRendered()) {
                this.renderCustomerWorkspace();
                this.finishWorkspaceLoading(root);
            }
        }

        finishWorkspaceLoading(root) {
            root?.classList.remove('nexa-customer-workspace-loading');
            root?.removeAttribute('aria-busy');
        }

        renderCustomerWorkspace() {
            const root = this.element;

            if (!root || root.querySelector('[data-nexa-contact-workspace]')) return;

            root.classList.add('nexa-contact-detail-workspace');
            const shell = this.buildShell();
            const nativeRecord = root.querySelector(':scope > .detail');
            root.prepend(shell);
            this.placeNativePanels(nativeRecord, shell);
            this.bindWorkspaceNavigation(shell);
            this.loadProfileImage(shell.querySelector('.nexa-contact-avatar'));
            this.loadContactNotes(shell);
            if (this.activateActivityAfterRender) {
                this.activateActivityAfterRender = false;
                shell.querySelector('[data-nexa-tab="activity"]')?.click();
            }
        }

        buildShell() {
            const shell = document.createElement('section');
            const name = this.model.get('name') || 'Contact';
            const firstName = this.model.get('firstName') || '';
            const lastName = this.model.get('lastName') || '';
            const initials = `${firstName.charAt(0)}${lastName.charAt(0)}`.trim() || name.charAt(0);
            const account = this.model.get('accountName') || 'No company associated';
            const title = this.model.get('title');

            shell.className = 'nexa-customer-record';
            shell.dataset.nexaContactWorkspace = 'true';
            shell.setAttribute('aria-label', `${name} customer profile`);
            shell.innerHTML = `
                <header class="nexa-customer-toolbar">
                    <strong>Customer record</strong>
                    <div class="nexa-native-actions" data-nexa-record-actions>
                        <button type="button" class="btn btn-primary nexa-toolbar-edit" data-nexa-edit-contact>
                            <span class="fas fa-pen" aria-hidden="true"></span><span>Edit</span>
                        </button>
                        <button type="button" class="btn btn-default nexa-toolbar-actions" data-nexa-actions-toggle aria-haspopup="true" aria-expanded="false">
                            <span class="fas fa-bars" aria-hidden="true"></span><span>Actions</span><span class="caret" aria-hidden="true"></span>
                        </button>
                    </div>
                </header>
                <aside class="nexa-customer-left" aria-label="Contact information">
                    <div class="nexa-customer-identity">
                        <div class="nexa-contact-avatar" aria-hidden="true"></div>
                        <div class="nexa-contact-heading">
                            <p class="nexa-contact-eyebrow">Contact</p>
                            <h2></h2>
                        </div>
                        <div class="nexa-contact-meta">
                            <p class="nexa-contact-subtitle"></p>
                            <p class="nexa-contact-primary-email"></p>
                        </div>
                    </div>
                    <div class="nexa-contact-quick-actions" role="toolbar" aria-label="Contact actions">
                        ${this.actionButton('note', 'fas fa-sticky-note', 'Note')}
                        ${this.actionButton('email', 'far fa-envelope', 'Email')}
                        ${this.actionButton('call', 'fas fa-phone', 'Call')}
                        ${this.actionButton('task', 'far fa-check-square', 'Task')}
                        ${this.actionButton('meeting', 'far fa-calendar', 'Meeting')}
                        ${this.moreActionButton()}
                    </div>
                    <div class="nexa-sidebar-stack" data-nexa-left-panels>
                        ${this.propertyCard('Key information', [
                            ['Website', this.externalLink(this.model.get('website')), true, 'website'],
                            ['Phone', this.model.get('phoneNumber'), false, 'phoneNumber'],
                            ['Address', this.formatAddress(), false, 'address'],
                            ['Contact owner', this.ownerLink(), true],
                            ['Lead status', this.leadStatusBadge(), true, 'leadStatus'],
                            ['Created date', this.createdAudit(), true],
                        ])}
                        ${this.propertyCard('Preferences & activity', [
                            ['Legal basis', this.optionLabel('legalBasis', this.model.get('legalBasis')), false, 'legalBasis'],
                            ['Preferred timezone', this.resolvedPreferredTimeZone(), false, 'preferredTimeZone'],
                            ['Original source', this.model.get('source'), false, 'source'],
                            ['Last website visit', this.model.get('lastWebsiteVisitAt')],
                        ])}
                        ${this.socialProfilesCard()}
                    </div>
                </aside>
                <main class="nexa-customer-centre">
                    <nav class="nexa-customer-tabs" aria-label="Customer workspace sections" role="tablist">
                        ${this.tabButton('overview', 'Overview', true)}
                        ${this.tabButton('activity', 'Activities')}
                        ${this.tabButton('notes', 'Notes')}
                        ${this.tabButton('sales', 'Sales')}
                        ${this.tabButton('marketing', 'Marketing')}
                        ${this.tabButton('service', 'Service')}
                    </nav>
                    <div class="nexa-customer-tab-content">
                        ${this.overviewPanel()}
                        <section class="nexa-customer-tab-panel" data-nexa-tab-panel="activity" role="tabpanel" hidden>
                            <div class="nexa-tab-heading"><div><p class="nexa-contact-eyebrow">Timeline</p><h3>Customer activity</h3></div><p>Upcoming activities and chronological CRM interactions.</p></div>
                            <div class="nexa-native-activity" data-nexa-activity-panels></div>
                        </section>
                        ${this.notesPanel()}
                        ${this.salesPanel()}
                        ${this.marketingPanel()}
                        ${this.servicePanel()}
                    </div>
                </main>
                <aside class="nexa-customer-right" aria-label="Contact associations">
                    <div class="nexa-sidebar-title"><div><p class="nexa-contact-eyebrow">Context</p><h3>Associations</h3></div></div>
                    <div class="nexa-association-stack" data-nexa-association-panels></div>
                    ${this.contextCard('Marketing membership', [
                        ['Segments and lists', 'No active membership recorded'],
                        ['Campaigns', 'No campaign enrollment recorded'],
                        ['Automation', 'No active journey enrollment'],
                    ])}
                </aside>`;

            shell.querySelector('.nexa-contact-avatar').textContent = initials.toUpperCase();
            shell.querySelector('.nexa-contact-heading h2').textContent = name;
            shell.querySelector('.nexa-contact-subtitle').textContent = [title, account].filter(Boolean).join(' at ');
            shell.querySelector('.nexa-contact-primary-email').textContent = this.model.get('emailAddress') || 'No email recorded';

            return shell;
        }

        placeNativePanels(nativeRecord, shell) {
            if (!nativeRecord) return;

            const activity = shell.querySelector('[data-nexa-activity-panels]');
            const associations = shell.querySelector('[data-nexa-association-panels]');
            const actions = shell.querySelector('[data-nexa-record-actions]');
            const recordButtons = nativeRecord.querySelector('.record-buttons') ||
                this.element.querySelector(':scope > .record-buttons');
            const bottom = nativeRecord.querySelector('.record-grid .bottom');

            if (recordButtons) {
                this.configureActionMenu(recordButtons, actions);
                actions.append(nativeRecord);
            }

            ['stream', 'activities', 'history', 'tasks'].forEach(name => {
                const panel = nativeRecord.querySelector(`[data-name="${name}"]`);
                if (panel) activity.append(panel);
            });
            ['accounts', 'opportunities', 'cases', 'documents', 'targetLists'].forEach(name => {
                const panel = bottom?.querySelector(`[data-name="${name}"]`);
                if (panel) associations.append(panel);
            });

            nativeRecord.classList.add('nexa-native-record-host');
        }

        configureActionMenu(recordButtons, actions) {
            const menuButton = actions.querySelector('[data-nexa-actions-toggle]');
            const nativeMenuButton = recordButtons.querySelector('.dropdown-item-list-button');
            const menu = recordButtons.querySelector('.dropdown-menu');
            const group = nativeMenuButton?.closest('.actions-btn-group');
            if (!menuButton || !nativeMenuButton || !menu || !group) return;

            // Stop the native sticky-record controller from moving this source on scroll.
            recordButtons.classList.remove('record-buttons', 'detail-button-container', 'button-container');
            recordButtons.classList.add('nexa-native-action-source');
            nativeMenuButton.removeAttribute('data-toggle');
            nativeMenuButton.setAttribute('aria-hidden', 'true');
            nativeMenuButton.setAttribute('tabindex', '-1');

            if (menuButton.dataset.nexaMenuBound === 'true') return;
            menuButton.dataset.nexaMenuBound = 'true';

            const close = () => {
                group.classList.remove('open');
                menuButton.setAttribute('aria-expanded', 'false');
            };
            const open = () => {
                group.classList.add('open');
                menuButton.setAttribute('aria-expanded', 'true');
            };

            menuButton.addEventListener('click', event => {
                event.preventDefault();
                event.stopPropagation();
                group.classList.contains('open') ? close() : open();
            });
            menuButton.addEventListener('keydown', event => {
                if (event.key === 'Escape') {
                    close();
                    menuButton.focus();
                }
                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    open();
                    menu.querySelector('a:not(.hidden), button:not(.hidden)')?.focus();
                }
            });
            menu.addEventListener('click', event => {
                if (event.target.closest('a, button')) setTimeout(close, 0);
            });

            if (this.actionMenuDocumentHandler) {
                document.removeEventListener('click', this.actionMenuDocumentHandler);
            }
            this.actionMenuDocumentHandler = event => {
                if (!group.contains(event.target)) close();
            };
            document.addEventListener('click', this.actionMenuDocumentHandler);
        }

        overviewPanel() {
            return `<section class="nexa-customer-tab-panel is-active" data-nexa-tab-panel="overview" role="tabpanel">
                <div class="nexa-tab-heading"><div><p class="nexa-contact-eyebrow">Customer 360</p><h3>Overview</h3></div><p>CRM, marketing and service context for this customer.</p></div>
                <div class="nexa-highlight-grid">
                    ${this.highlight('Lifecycle', this.optionLabel('lifecycleStage', this.model.get('lifecycleStage')), 'fas fa-route')}
                    ${this.highlight('Marketing status', this.optionLabel('marketingStatus', this.model.get('marketingStatus')), 'fas fa-bullhorn')}
                    ${this.highlight('Lead score', this.model.get('leadScore'), 'fas fa-star')}
                    ${this.highlight('Last activity', this.model.get('modifiedAt'), 'far fa-clock')}
                </div>
                <div class="nexa-overview-cards">
                    ${this.contextCard('Recent engagement', [
                        ['CRM activity', 'Review the Activities tab for the complete timeline'],
                        ['Marketing email', 'No delivery or engagement recorded'],
                        ['Website behavior', this.model.get('lastWebsiteVisitAt') || 'No identified website visit'],
                    ])}
                    ${this.contextCard('Customer health', [
                        ['Sales relationship', this.optionLabel('leadStatus', this.model.get('leadStatus'))],
                        ['Service status', 'No open service outcome recorded'],
                        ['Consent', this.optionLabel('legalBasis', this.model.get('legalBasis'))],
                    ])}
                </div>
            </section>`;
        }

        notesPanel() {
            return `<section class="nexa-customer-tab-panel nexa-notes-workspace" data-nexa-tab-panel="notes" role="tabpanel" hidden>
                <div class="nexa-notes-toolbar">
                    <div class="nexa-notes-toolbar-primary">
                        <label class="nexa-notes-search"><span class="sr-only">Search notes</span><input type="search" data-nexa-notes-search placeholder="Search notes"><span class="fas fa-search" aria-hidden="true"></span></label>
                        <div class="nexa-notes-toolbar-actions">
                            <button type="button" class="nexa-collapse-notes" data-nexa-collapse-notes>Collapse all <span class="fas fa-caret-down" aria-hidden="true"></span></button>
                            <button type="button" class="btn btn-default" data-nexa-create-note><span class="far fa-edit" aria-hidden="true"></span><span>Create a note</span></button>
                        </div>
                    </div>
                    <button type="button" class="btn btn-default nexa-notes-filter-toggle" data-nexa-notes-filter-toggle aria-expanded="true"><span class="fas fa-sliders-h" aria-hidden="true"></span><span>Filters</span></button>
                    <div class="nexa-notes-filters" data-nexa-notes-filters>
                        <div class="nexa-note-filter" data-nexa-period-filter>
                            <button type="button" data-nexa-period-toggle aria-haspopup="true" aria-expanded="false"><span data-nexa-period-label>All time</span><span class="fas fa-caret-down" aria-hidden="true"></span></button>
                            <div class="nexa-note-filter-menu nexa-note-period-menu" data-nexa-period-menu hidden>
                                ${this.notePeriodOptions().map(([value, label]) => `<button type="button" data-nexa-period="${value}">${label}</button>`).join('')}
                            </div>
                        </div>
                        <div class="nexa-note-filter" data-nexa-owner-filter>
                            <button type="button" data-nexa-owner-toggle aria-haspopup="true" aria-expanded="false"><span data-nexa-owner-label>Activity assigned to</span><span class="fas fa-caret-down" aria-hidden="true"></span></button>
                            <div class="nexa-note-filter-menu nexa-note-owner-menu" data-nexa-owner-menu hidden>
                                <label><span class="fas fa-search" aria-hidden="true"></span><input type="search" data-nexa-owner-search placeholder="Search owners" aria-label="Search owners"></label>
                                <div data-nexa-owner-options></div>
                            </div>
                        </div>
                    </div>
                </div>
                <section class="nexa-contact-notes" aria-labelledby="nexa-contact-notes-title">
                    <h4 id="nexa-contact-notes-title" class="sr-only">Saved notes</h4><span class="sr-only" data-nexa-note-count>0 notes</span>
                    <div class="nexa-contact-note-list" data-nexa-note-list aria-live="polite">
                        <div class="nexa-note-loading"><span class="fas fa-circle-notch fa-spin" aria-hidden="true"></span><span>Loading notes</span></div>
                    </div>
                </section>
            </section>`;
        }

        notePeriodOptions() {
            return [
                ['all', 'All time'], ['today', 'Today'], ['yesterday', 'Yesterday'],
                ['this-week', 'This week'], ['last-week', 'Last week'], ['last-7-days', 'Last 7 days'],
                ['this-month', 'This month'], ['last-month', 'Last month'], ['last-30-days', 'Last 30 days'],
                ['last-90-days', 'Last 90 days'], ['last-quarter', 'Last quarter'],
                ['this-quarter', 'This quarter'], ['this-year', 'This year'], ['last-year', 'Last year'],
            ];
        }

        salesPanel() {
            return this.domainPanel('sales', 'Sales workspace', 'Deal context and sales qualification for this contact.', [
                ['Lifecycle stage', this.optionLabel('lifecycleStage', this.model.get('lifecycleStage'))],
                ['Lead status', this.optionLabel('leadStatus', this.model.get('leadStatus'))],
                ['Lead score', this.model.get('leadScore')],
                ['Contact owner', this.model.get('assignedUserName')],
            ], 'Associated deals remain visible in the right sidebar.');
        }

        marketingPanel() {
            return this.domainPanel('marketing', 'Marketing engagement', 'Consent-aware campaigns, behavior and automation for this contact.', [
                ['Marketing status', this.optionLabel('marketingStatus', this.model.get('marketingStatus'))],
                ['Original source', this.model.get('source')],
                ['Last website visit', this.model.get('lastWebsiteVisitAt')],
                ['Legal basis', this.optionLabel('legalBasis', this.model.get('legalBasis'))],
            ], 'Campaign delivery, opens, clicks, forms, segments and journey events will appear here as their modules become operational.');
        }

        servicePanel() {
            return this.domainPanel('service', 'Service relationship', 'Cases, conversations and support outcomes associated with this customer.', [
                ['Open cases', 'Not recorded'],
                ['Latest conversation', 'Not recorded'],
                ['Satisfaction', 'Not recorded'],
                ['Service owner', this.model.get('assignedUserName')],
            ], 'Associated cases remain visible in the right sidebar.');
        }

        domainPanel(id, title, description, facts, emptyMessage) {
            return `<section class="nexa-customer-tab-panel" data-nexa-tab-panel="${id}" role="tabpanel" hidden>
                <div class="nexa-tab-heading"><div><p class="nexa-contact-eyebrow">${this.escape(id)}</p><h3>${this.escape(title)}</h3></div><p>${this.escape(description)}</p></div>
                <dl class="nexa-domain-facts">${facts.map(([label, value]) => this.fact(label, value)).join('')}</dl>
                <div class="nexa-domain-empty"><span class="far fa-folder-open" aria-hidden="true"></span><p>${this.escape(emptyMessage)}</p></div>
            </section>`;
        }

        bindWorkspaceNavigation(shell) {
            shell.querySelectorAll('[data-nexa-tab]').forEach(tab => {
                tab.addEventListener('click', () => {
                    const id = tab.dataset.nexaTab;
                    shell.querySelectorAll('[data-nexa-tab]').forEach(item => item.setAttribute('aria-selected', String(item === tab)));
                    shell.querySelectorAll('[data-nexa-tab-panel]').forEach(panel => {
                        const active = panel.dataset.nexaTabPanel === id;
                        panel.hidden = !active;
                        panel.classList.toggle('is-active', active);
                    });
                });
            });
            shell.querySelectorAll('[data-nexa-contact-action]').forEach(button => {
                button.addEventListener('click', () => this.openActivity(button.dataset.nexaContactAction));
            });
            shell.querySelector('[data-nexa-more-actions]')?.addEventListener('click', event => {
                event.stopPropagation();
                this.toggleCustomerCommandMenu(event.currentTarget);
            });
            shell.querySelectorAll('[data-nexa-edit-contact]').forEach(button => {
                button.addEventListener('click', () => this.openEditView());
            });
            shell.querySelectorAll('[data-nexa-inline-detail]').forEach(row => {
                row.querySelector('[data-nexa-inline-trigger]')?.addEventListener('click', event => {
                    this.startInlineDetailEdit(event, row);
                });
                row.addEventListener('dblclick', event => this.startInlineDetailEdit(event, row));
                row.addEventListener('keydown', event => {
                    if (['Enter', 'F2'].includes(event.key) && !row.classList.contains('is-editing')) {
                        this.startInlineDetailEdit(event, row);
                    }
                });
            });
            this.bindContactNotesWorkspace(shell);
        }

        bindContactNotesWorkspace(shell) {
            this.contactNoteFilter = this.contactNoteFilter || {query: '', period: 'all', owner: 'all'};
            this.collapsedContactNoteIds = this.collapsedContactNoteIds || new Set();

            shell.querySelector('[data-nexa-create-note]')?.addEventListener('click', () => this.openNoteDialog());
            shell.querySelector('[data-nexa-notes-search]')?.addEventListener('input', event => {
                this.contactNoteFilter.query = event.currentTarget.value.trim().toLowerCase();
                this.renderContactNotes(shell);
            });
            shell.querySelector('[data-nexa-notes-filter-toggle]')?.addEventListener('click', event => {
                const filters = shell.querySelector('[data-nexa-notes-filters]');
                filters.hidden = !filters.hidden;
                event.currentTarget.setAttribute('aria-expanded', String(!filters.hidden));
            });
            shell.querySelector('[data-nexa-period-toggle]')?.addEventListener('click', event => {
                event.stopPropagation();
                this.toggleNoteFilterMenu(shell, 'period');
            });
            shell.querySelector('[data-nexa-owner-toggle]')?.addEventListener('click', event => {
                event.stopPropagation();
                this.toggleNoteFilterMenu(shell, 'owner');
            });
            shell.querySelectorAll('[data-nexa-period]').forEach(button => {
                button.addEventListener('click', () => {
                    this.contactNoteFilter.period = button.dataset.nexaPeriod;
                    shell.querySelector('[data-nexa-period-label]').textContent = button.textContent.trim();
                    this.closeNoteFilterMenus(shell);
                    this.renderContactNotes(shell);
                });
            });
            shell.querySelector('[data-nexa-owner-search]')?.addEventListener('input', event => {
                const term = event.currentTarget.value.trim().toLowerCase();
                shell.querySelectorAll('[data-nexa-owner-option]').forEach(button => {
                    button.hidden = term && !button.dataset.nexaOwnerSearch.includes(term);
                });
            });
            shell.querySelector('[data-nexa-collapse-notes]')?.addEventListener('click', () => {
                const visible = this.filteredContactNotes();
                const allCollapsed = visible.length > 0 && visible.every(note => this.collapsedContactNoteIds.has(note.id));
                if (allCollapsed) {
                    visible.forEach(note => this.collapsedContactNoteIds.delete(note.id));
                } else {
                    visible.forEach(note => this.collapsedContactNoteIds.add(note.id));
                }
                this.renderContactNotes(shell);
            });

            if (this.notesFilterDocumentHandler) document.removeEventListener('click', this.notesFilterDocumentHandler);
            this.notesFilterDocumentHandler = event => {
                if (!event.target.closest('[data-nexa-period-filter], [data-nexa-owner-filter]')) {
                    this.closeNoteFilterMenus(shell);
                }
            };
            document.addEventListener('click', this.notesFilterDocumentHandler);
        }

        toggleNoteFilterMenu(shell, type) {
            const ownMenu = shell.querySelector(`[data-nexa-${type}-menu]`);
            const ownToggle = shell.querySelector(`[data-nexa-${type}-toggle]`);
            const otherType = type === 'period' ? 'owner' : 'period';
            const otherMenu = shell.querySelector(`[data-nexa-${otherType}-menu]`);
            const otherToggle = shell.querySelector(`[data-nexa-${otherType}-toggle]`);
            const opening = ownMenu.hidden;
            ownMenu.hidden = !opening;
            ownToggle.setAttribute('aria-expanded', String(opening));
            otherMenu.hidden = true;
            otherToggle.setAttribute('aria-expanded', 'false');
            if (opening && type === 'owner') window.setTimeout(() => ownMenu.querySelector('input')?.focus(), 0);
        }

        closeNoteFilterMenus(shell) {
            ['period', 'owner'].forEach(type => {
                shell.querySelector(`[data-nexa-${type}-menu]`)?.setAttribute('hidden', '');
                shell.querySelector(`[data-nexa-${type}-toggle]`)?.setAttribute('aria-expanded', 'false');
            });
        }

        actionButton(type, icon, label) {
            return `<button type="button" class="btn btn-link" data-nexa-contact-action="${type}"><span class="${icon}" aria-hidden="true"></span><span>${label}</span></button>`;
        }

        moreActionButton() {
            return `<button type="button" class="btn btn-link nexa-more-action-button" data-nexa-more-actions
                aria-label="More customer actions" aria-haspopup="dialog" aria-expanded="false"
                title="More customer actions"><span class="fas fa-ellipsis-h" aria-hidden="true"></span><span>More</span></button>`;
        }

        customerCommandGroups() {
            return [
                {
                    label: 'Log communication',
                    actions: [
                        ['sms', 'fas fa-comment-alt', 'Log SMS'],
                        ['whatsapp', null, 'Log WhatsApp message', 'whatsapp.svg'],
                        ['linkedin', null, 'Log LinkedIn message', 'linkedin.svg'],
                        ['call-log', 'fas fa-phone-alt', 'Log call'],
                        ['meeting-log', 'far fa-calendar-check', 'Log meeting'],
                        ['email-log', 'far fa-envelope', 'Log email'],
                        ['postal-mail', 'fas fa-mail-bulk', 'Log postal mail'],
                        ['live-chat', 'far fa-comments', 'Log live chat'],
                        ['other', 'far fa-sticky-note', 'Log other interaction'],
                    ],
                },
            ];
        }

        customerCommandIcon(icon, asset) {
            if (asset) {
                const source = `${this.getBasePath()}client/custom/img/social/${asset}`;
                return `<img class="nexa-command-brand-icon" src="${this.escape(source)}" alt="" aria-hidden="true">`;
            }

            return `<span class="${icon}" aria-hidden="true"></span>`;
        }

        toggleCustomerCommandMenu(anchor) {
            if (this.customerCommandMenu) {
                this.closeCustomerCommandMenu();
                return;
            }

            const menu = document.createElement('section');
            menu.className = 'nexa-customer-command-menu';
            menu.dataset.nexaCustomerCommandMenu = 'true';
            menu.setAttribute('role', 'dialog');
            menu.setAttribute('aria-modal', 'false');
            menu.setAttribute('aria-label', 'More customer actions');
            menu.innerHTML = `
                <div class="nexa-command-search">
                    <span class="fas fa-search" aria-hidden="true"></span>
                    <input type="search" class="form-control" data-nexa-command-search
                        placeholder="Search customer actions" aria-label="Search customer actions">
                </div>
                <div class="nexa-command-groups">
                    ${this.customerCommandGroups().map(group => `
                        <section class="nexa-command-group" data-nexa-command-group>
                            <h3>${this.escape(group.label)}</h3>
                            ${group.actions.map(([action, icon, label, asset]) => `
                                <button type="button" data-nexa-command="${this.escape(action)}"
                                    data-nexa-command-label="${this.escape(label.toLowerCase())}">
                                    ${this.customerCommandIcon(icon, asset)}<span>${this.escape(label)}</span>
                                </button>`).join('')}
                        </section>`).join('')}
                </div>
                <p class="nexa-command-empty" data-nexa-command-empty hidden>No matching customer actions.</p>`;

            document.body.append(menu);
            this.customerCommandMenu = menu;
            this.customerCommandAnchor = anchor;
            anchor.setAttribute('aria-expanded', 'true');
            this.positionCustomerCommandMenu();

            const search = menu.querySelector('[data-nexa-command-search]');
            search.addEventListener('input', () => this.filterCustomerCommands(search.value));
            menu.addEventListener('click', event => {
                const command = event.target.closest('[data-nexa-command]');
                if (!command) return;
                const action = command.dataset.nexaCommand;
                const returnFocus = this.customerCommandAnchor;
                this.closeCustomerCommandMenu();
                this.openInteractionDialog(action, returnFocus);
            });
            menu.addEventListener('keydown', event => this.handleCustomerCommandKeys(event));

            this.customerCommandOutsideHandler = event => {
                if (!menu.contains(event.target) && event.target !== anchor) this.closeCustomerCommandMenu();
            };
            this.customerCommandPositionHandler = () => this.positionCustomerCommandMenu();
            document.addEventListener('click', this.customerCommandOutsideHandler);
            window.addEventListener('resize', this.customerCommandPositionHandler);
            window.addEventListener('scroll', this.customerCommandPositionHandler, true);
            window.setTimeout(() => search.focus(), 0);
        }

        positionCustomerCommandMenu() {
            const menu = this.customerCommandMenu;
            const anchor = this.customerCommandAnchor;
            if (!menu || !anchor?.isConnected) return;

            const rect = anchor.getBoundingClientRect();
            const margin = 12;
            const width = Math.min(330, window.innerWidth - (margin * 2));
            const left = Math.min(Math.max(margin, rect.left), window.innerWidth - width - margin);
            const availableBelow = window.innerHeight - rect.bottom - margin;
            const top = availableBelow >= Math.min(menu.scrollHeight, 440)
                ? rect.bottom + 7
                : Math.max(margin, rect.top - Math.min(menu.scrollHeight, 440) - 7);

            menu.style.width = `${width}px`;
            menu.style.left = `${left}px`;
            menu.style.top = `${top}px`;
        }

        filterCustomerCommands(query) {
            const menu = this.customerCommandMenu;
            if (!menu) return;

            const term = query.trim().toLowerCase();
            let visibleCount = 0;
            menu.querySelectorAll('[data-nexa-command]').forEach(button => {
                const visible = !term || button.dataset.nexaCommandLabel.includes(term);
                button.hidden = !visible;
                if (visible) visibleCount++;
            });
            menu.querySelectorAll('[data-nexa-command-group]').forEach(group => {
                group.hidden = !group.querySelector('[data-nexa-command]:not([hidden])');
            });
            menu.querySelector('[data-nexa-command-empty]').hidden = visibleCount !== 0;
        }

        handleCustomerCommandKeys(event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                const anchor = this.customerCommandAnchor;
                this.closeCustomerCommandMenu();
                anchor?.focus();
                return;
            }
            if (!['ArrowDown', 'ArrowUp'].includes(event.key)) return;

            const buttons = [...this.customerCommandMenu.querySelectorAll('[data-nexa-command]:not([hidden])')];
            if (!buttons.length) return;
            event.preventDefault();
            const current = buttons.indexOf(document.activeElement);
            const offset = event.key === 'ArrowDown' ? 1 : -1;
            buttons[(current + offset + buttons.length) % buttons.length].focus();
        }

        closeCustomerCommandMenu() {
            if (this.customerCommandOutsideHandler) {
                document.removeEventListener('click', this.customerCommandOutsideHandler);
            }
            if (this.customerCommandPositionHandler) {
                window.removeEventListener('resize', this.customerCommandPositionHandler);
                window.removeEventListener('scroll', this.customerCommandPositionHandler, true);
            }
            this.customerCommandAnchor?.setAttribute('aria-expanded', 'false');
            this.customerCommandMenu?.remove();
            this.customerCommandMenu = null;
            this.customerCommandAnchor = null;
            this.customerCommandOutsideHandler = null;
            this.customerCommandPositionHandler = null;
        }

        interactionChannels() {
            return {
                sms: 'SMS',
                whatsapp: 'WhatsApp message',
                linkedin: 'LinkedIn message',
                'call-log': 'Call',
                'meeting-log': 'Meeting',
                'email-log': 'Email',
                'postal-mail': 'Postal mail',
                'live-chat': 'Live chat',
                other: 'Other interaction',
            };
        }

        openInteractionDialog(channelKey, returnFocus = null) {
            this.closeInteractionDialog();
            const channel = this.interactionChannels()[channelKey] || 'Interaction';
            const overlay = document.createElement('div');
            const now = new Date();
            const localDate = new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
            overlay.className = 'nexa-interaction-overlay';
            overlay.dataset.nexaInteractionDialog = 'true';
            overlay.innerHTML = `
                <section class="nexa-interaction-dialog" role="dialog" aria-modal="true"
                    aria-labelledby="nexa-interaction-title" aria-describedby="nexa-interaction-help">
                    <header>
                        <div><p>Customer activity</p><h2 id="nexa-interaction-title">Log ${this.escape(channel)}</h2></div>
                        <button type="button" class="nexa-dialog-close" data-nexa-dialog-close aria-label="Close">
                            <span class="fas fa-times" aria-hidden="true"></span>
                        </button>
                    </header>
                    <form data-nexa-interaction-form>
                        <p id="nexa-interaction-help" class="nexa-interaction-help">Record a completed interaction on this Contact's activity timeline.</p>
                        <div class="nexa-interaction-grid">
                            <label><span>Direction</span><select class="form-control" name="direction">
                                <option value="Outbound">Outbound</option><option value="Inbound">Inbound</option>
                            </select></label>
                            <label><span>Date and time</span><input class="form-control" type="datetime-local" name="occurredAt" value="${localDate}" required></label>
                        </div>
                        <label><span>Subject</span><input class="form-control" type="text" name="subject" maxlength="160" placeholder="Short summary" required></label>
                        <label><span>Outcome</span><select class="form-control" name="outcome">
                            <option value="">Not specified</option><option>Connected</option><option>Completed</option>
                            <option>No response</option><option>Left message</option><option>Follow-up required</option>
                        </select></label>
                        <label><span>Notes</span><textarea class="form-control" name="notes" rows="5" maxlength="5000" placeholder="Add useful context for the team"></textarea></label>
                        <p class="nexa-interaction-error" data-nexa-interaction-error role="alert" hidden></p>
                        <footer>
                            <button type="button" class="btn btn-default" data-nexa-dialog-close>Cancel</button>
                            <button type="submit" class="btn btn-primary" data-nexa-log-interaction>
                                <span class="fas fa-check" aria-hidden="true"></span><span>Log interaction</span>
                            </button>
                        </footer>
                    </form>
                </section>`;

            document.body.append(overlay);
            this.interactionDialog = overlay;
            this.interactionDialogReturnFocus = returnFocus;
            overlay.querySelectorAll('[data-nexa-dialog-close]').forEach(button => {
                button.addEventListener('click', () => this.closeInteractionDialog());
            });
            overlay.addEventListener('mousedown', event => {
                if (event.target === overlay) this.closeInteractionDialog();
            });
            overlay.addEventListener('keydown', event => this.handleInteractionDialogKeys(event));
            overlay.querySelector('[data-nexa-interaction-form]').addEventListener('submit', event => {
                event.preventDefault();
                this.saveInteraction(channel, new FormData(event.currentTarget));
            });
            window.setTimeout(() => overlay.querySelector('[name="subject"]')?.focus(), 0);
        }

        handleInteractionDialogKeys(event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                this.closeInteractionDialog();
                return;
            }
            if (event.key !== 'Tab') return;

            const controls = [...this.interactionDialog.querySelectorAll('button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled])')];
            if (!controls.length) return;
            const first = controls[0];
            const last = controls[controls.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }

        async saveInteraction(channel, formData) {
            const dialog = this.interactionDialog;
            if (!dialog || this.interactionSavePending) return;

            const subject = String(formData.get('subject') || '').trim();
            const occurredAt = String(formData.get('occurredAt') || '').trim();
            const error = dialog.querySelector('[data-nexa-interaction-error]');
            if (!subject || !occurredAt) {
                error.textContent = 'Enter a subject and the interaction date and time.';
                error.hidden = false;
                return;
            }

            const direction = String(formData.get('direction') || 'Outbound');
            const outcome = String(formData.get('outcome') || '').trim();
            const notes = String(formData.get('notes') || '').trim();
            const lines = [
                `[${channel} - ${direction}] ${subject}`,
                `Occurred: ${occurredAt.replace('T', ' ')}`,
                outcome ? `Outcome: ${outcome}` : '',
                notes ? `\n${notes}` : '',
            ].filter(Boolean);
            const submit = dialog.querySelector('[data-nexa-log-interaction]');

            this.interactionSavePending = true;
            submit.disabled = true;
            submit.classList.add('is-loading');
            error.hidden = true;
            try {
                const note = await this.getModelFactory().create('Note');
                note.set({
                    type: 'Post',
                    post: lines.join('\n'),
                    parentType: this.model.entityType,
                    parentId: this.model.id,
                });
                await note.save(null);
                this.closeInteractionDialog();
                Espo.Ui.success(`${channel} logged`);
                this.activateActivityAfterRender = true;
                await this.reRender();
            } catch (saveError) {
                error.textContent = 'The interaction could not be saved. Check your access and try again.';
                error.hidden = false;
                submit.disabled = false;
                submit.classList.remove('is-loading');
            } finally {
                this.interactionSavePending = false;
            }
        }

        closeInteractionDialog() {
            const returnFocus = this.interactionDialogReturnFocus;
            this.interactionDialog?.remove();
            this.interactionDialog = null;
            this.interactionDialogReturnFocus = null;
            this.interactionSavePending = false;
            returnFocus?.focus?.();
        }

        tabButton(id, label, active = false) {
            return `<button type="button" role="tab" aria-selected="${active}" data-nexa-tab="${id}">${label}</button>`;
        }

        propertyCard(title, values) {
            return `<section class="nexa-sidebar-card"><div class="nexa-sidebar-card-heading"><h3>${this.escape(title)}</h3></div><dl>${values.map(([label, value, isHtml, editableField]) => {
                if (editableField) return this.editableFact(label, value, editableField, isHtml);
                return isHtml ? this.factHtml(label, value) : this.fact(label, value);
            }).join('')}</dl></section>`;
        }

        editableFact(label, value, field, isHtml = false) {
            const display = isHtml ? value : this.escape(this.displayValue(value));
            return `<div class="nexa-record-fact nexa-inline-detail" data-nexa-inline-detail data-field="${this.escape(field)}" tabindex="0" aria-label="${this.escape(label)}. Press Enter or use the edit button to update.">
                <dt>${this.escape(label)}</dt>
                <dd><span class="nexa-inline-detail-display" data-nexa-inline-display>${display}</span><button type="button" class="nexa-inline-detail-trigger" data-nexa-inline-trigger aria-label="Edit ${this.escape(label)}"><span class="fas fa-pencil-alt" aria-hidden="true"></span></button></dd>
            </div>`;
        }

        inlineDetailConfig(field) {
            const fields = {
                website: {inputType: 'text', maxLength: 255},
                phoneNumber: {inputType: 'tel', maxLength: 50},
                address: {
                    type: 'address',
                    fields: ['addressStreet', 'addressCity', 'addressState', 'addressPostalCode', 'addressCountry'],
                },
                leadStatus: {type: 'dropdown'},
                legalBasis: {type: 'dropdown'},
                preferredTimeZone: {inputType: 'text', maxLength: 100},
                source: {type: 'dropdown'},
            };

            return fields[field] || null;
        }

        startInlineDetailEdit(event, row) {
            if (this.inlineDetailState?.saving || row.classList.contains('is-editing')) return;
            if (event.target.closest('a') && !event.target.closest('[data-nexa-inline-trigger]')) return;

            const field = row.dataset.field;
            const config = this.inlineDetailConfig(field);
            const aclFields = config?.fields || [field];
            if (!config || !this.getAcl().checkModel(this.model, 'edit') ||
                aclFields.some(name => !this.getAcl().checkField('Contact', name, 'edit'))) {
                Espo.Ui.error(this.translate('Access denied'));
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            this.cancelInlineDetailEdit();

            const display = row.querySelector('[data-nexa-inline-display]');
            const trigger = row.querySelector('[data-nexa-inline-trigger]');
            const originalValue = config.type === 'address'
                ? Object.fromEntries(config.fields.map(name => [name, this.model.get(name) ?? '']))
                : this.model.get(field) ?? '';
            const editor = this.createInlineDetailEditor(field, config, originalValue);

            this.inlineDetailState = {
                row,
                display,
                trigger,
                field,
                config,
                editor,
                originalValue,
                originalHtml: display.innerHTML,
                saving: false,
            };
            row.classList.add('is-editing');
            display.replaceChildren(editor);
            trigger.hidden = true;

            const firstControl = editor.matches('input, select') ? editor : editor.querySelector('input, select');
            firstControl?.focus();
            firstControl?.select?.();

            editor.addEventListener('keydown', keyEvent => {
                if (keyEvent.key === 'Enter') {
                    keyEvent.preventDefault();
                    this.saveInlineDetailEdit();
                } else if (keyEvent.key === 'Escape') {
                    keyEvent.preventDefault();
                    this.cancelInlineDetailEdit();
                    row.focus();
                }
            });
            editor.addEventListener('focusout', () => window.setTimeout(() => {
                if (this.inlineDetailState?.row === row && !row.contains(document.activeElement)) {
                    this.saveInlineDetailEdit();
                }
            }, 0));
            if (editor instanceof HTMLSelectElement) {
                editor.addEventListener('change', () => this.saveInlineDetailEdit(), {once: true});
            }
        }

        createInlineDetailEditor(field, config, value) {
            if (config.type === 'address') {
                const editor = document.createElement('div');
                const labels = {
                    addressStreet: 'Street',
                    addressCity: 'City',
                    addressState: 'State',
                    addressPostalCode: 'Postal code',
                    addressCountry: 'Country',
                };
                editor.className = 'nexa-inline-address-editor';
                config.fields.forEach(name => {
                    const input = document.createElement('input');
                    input.type = 'text';
                    input.className = 'form-control nexa-inline-detail-input';
                    input.dataset.addressField = name;
                    input.value = value[name] || '';
                    input.placeholder = labels[name];
                    input.setAttribute('aria-label', labels[name]);
                    editor.append(input);
                });
                return editor;
            }

            const options = config.type === 'dropdown'
                ? this.getMetadata().get(`entityDefs.Contact.fields.${field}.options`) || []
                : [];
            if (config.type === 'dropdown' && options.length) {
                const select = document.createElement('select');
                select.className = 'form-control nexa-inline-detail-input';
                select.setAttribute('aria-label', `Edit ${field}`);
                ['', ...options.filter(option => option !== '')].forEach(optionValue => {
                    const option = document.createElement('option');
                    option.value = optionValue;
                    option.textContent = optionValue === '' ? this.translate('None') : this.optionLabel(field, optionValue);
                    option.selected = optionValue === value;
                    select.append(option);
                });
                return select;
            }

            const input = document.createElement('input');
            input.type = config.inputType || 'text';
            input.className = 'form-control nexa-inline-detail-input';
            input.value = value;
            if (config.maxLength) input.maxLength = config.maxLength;
            input.setAttribute('aria-label', `Edit ${field}`);
            return input;
        }

        readInlineDetailValue(state) {
            if (state.config.type === 'address') {
                return Object.fromEntries([...state.editor.querySelectorAll('[data-address-field]')]
                    .map(input => [input.dataset.addressField, input.value.trim()]));
            }

            return state.editor.value.trim();
        }

        async saveInlineDetailEdit() {
            const state = this.inlineDetailState;
            if (!state || state.saving) return;

            const value = this.readInlineDetailValue(state);
            if (JSON.stringify(value) === JSON.stringify(state.originalValue)) {
                this.cancelInlineDetailEdit();
                return;
            }

            state.saving = true;
            state.row.classList.remove('is-editing');
            state.row.classList.add('is-saving');
            state.display.textContent = 'Saving...';

            try {
                const attributes = state.config.type === 'address'
                    ? Object.fromEntries(Object.entries(value).map(([name, item]) => [name, item || null]))
                    : {[state.field]: value || null};
                await this.model.save(attributes, {patch: true});
                if (!state.row.isConnected) {
                    this.inlineDetailState = null;
                    return;
                }
                state.display.innerHTML = this.inlineDetailDisplay(state.field);
                state.row.classList.remove('is-saving');
                state.row.classList.add('is-saved');
                state.trigger.hidden = false;
                window.setTimeout(() => state.row.classList.remove('is-saved'), 1600);
                this.inlineDetailState = null;
            } catch (error) {
                if (state.config.type === 'address') {
                    this.model.set(state.originalValue, {silent: true});
                } else {
                    this.model.set(state.field, state.originalValue, {silent: true});
                }
                state.display.innerHTML = state.originalHtml;
                state.row.classList.remove('is-saving');
                state.row.classList.add('is-error');
                state.trigger.hidden = false;
                this.inlineDetailState = null;
                Espo.Ui.error(this.translate('Error occurred'));
                window.setTimeout(() => state.row.classList.remove('is-error'), 2200);
            }
        }

        cancelInlineDetailEdit() {
            const state = this.inlineDetailState;
            if (!state || state.saving) return;
            state.display.innerHTML = state.originalHtml;
            state.row.classList.remove('is-editing');
            state.trigger.hidden = false;
            this.inlineDetailState = null;
        }

        inlineDetailDisplay(field) {
            if (field === 'website') return this.externalLink(this.model.get(field));
            if (field === 'address') return this.escape(this.displayValue(this.formatAddress()));
            if (field === 'leadStatus') return this.leadStatusBadge();
            if (field === 'legalBasis' || field === 'source') {
                return this.escape(this.optionLabel(field, this.model.get(field)));
            }
            if (field === 'preferredTimeZone') return this.escape(this.resolvedPreferredTimeZone());
            return this.escape(this.displayValue(this.model.get(field)));
        }

        openEditView() {
            this.getRouter().navigate(`#Contact/edit/${this.model.id}`, {trigger: true});
        }

        contextCard(title, values) {
            return `<section class="nexa-context-card"><h4>${this.escape(title)}</h4><dl>${values.map(([label, value]) => this.fact(label, value)).join('')}</dl></section>`;
        }

        fact(label, value) {
            return `<div class="nexa-record-fact"><dt>${this.escape(label)}</dt><dd>${this.escape(this.displayValue(value))}</dd></div>`;
        }

        factHtml(label, value) {
            return `<div class="nexa-record-fact"><dt>${this.escape(label)}</dt><dd>${value}</dd></div>`;
        }

        highlight(label, value, icon) {
            return `<article class="nexa-highlight"><span class="${icon}" aria-hidden="true"></span><div><p>${this.escape(label)}</p><strong>${this.escape(this.displayValue(value))}</strong></div></article>`;
        }

        openActivity(type) {
            if (type === 'email' && typeof this.actionComposeEmail === 'function') return this.actionComposeEmail();
            if (type === 'note') {
                return this.openNoteDialog();
            }
            const activityMap = {call: 'Call', meeting: 'Meeting', task: 'Task'};
            if (typeof this.createActivity === 'function') return this.createActivity(activityMap[type]);
            this.element.querySelector('[data-nexa-tab="activity"]')?.click();
        }

        async openNoteDialog() {
            this.closeNoteDialog();
            const contactName = this.model.get('name') || 'Contact';
            const overlay = document.createElement('div');
            overlay.className = 'nexa-note-overlay';
            overlay.dataset.nexaNoteDialog = 'true';
            overlay.innerHTML = `
                <section class="nexa-note-dialog" role="dialog" aria-modal="true"
                    aria-labelledby="nexa-note-dialog-title" aria-describedby="nexa-note-dialog-help">
                    <header>
                        <div><p>Customer workspace</p><h2 id="nexa-note-dialog-title">Note</h2></div>
                        <button type="button" class="nexa-dialog-close" data-nexa-note-close aria-label="Close note">
                            <span class="fas fa-times" aria-hidden="true"></span>
                        </button>
                    </header>
                    <form data-nexa-note-form>
                        <div class="nexa-note-recipient"><span>For</span><strong>${this.escape(contactName)}</strong></div>
                        <p id="nexa-note-dialog-help" class="nexa-note-help">Add context that your team should retain on this customer record.</p>
                        <div class="nexa-native-rich-editor" data-nexa-note-editor-host aria-label="Note content"><div class="nexa-note-editor-loading"><span class="fas fa-circle-notch fa-spin" aria-hidden="true"></span><span>Loading editor</span></div></div>
                        <p class="nexa-note-error" data-nexa-note-error role="alert" hidden></p>
                        <footer>
                            <button type="button" class="btn btn-default" data-nexa-note-close>Cancel</button>
                            <button type="submit" class="btn btn-primary" data-nexa-save-note disabled><span class="fas fa-check" aria-hidden="true"></span><span>Add note</span></button>
                        </footer>
                    </form>
                </section>`;

            document.body.append(overlay);
            this.noteDialog = overlay;
            this.noteDialogReturnFocus = this.element.querySelector('[data-nexa-contact-action="note"]');
            overlay.querySelectorAll('[data-nexa-note-close]').forEach(button => {
                button.addEventListener('click', () => this.closeNoteDialog());
            });
            overlay.addEventListener('mousedown', event => {
                if (event.target === overlay) this.closeNoteDialog();
            });
            overlay.addEventListener('keydown', event => this.handleNoteDialogKeys(event));
            overlay.querySelector('[data-nexa-note-form]').addEventListener('submit', event => {
                event.preventDefault();
                this.saveContactNote(event.currentTarget);
            });
            try {
                this.noteEditorModel = await this.getModelFactory().create('Note');
                if (!this.noteDialog?.isConnected) return;
                this.noteEditorView = await this.createView('nexaNoteEditor', 'views/fields/wysiwyg', {
                    fullSelector: '[data-nexa-note-editor-host]',
                    model: this.noteEditorModel,
                    name: 'post',
                    mode: 'edit',
                    params: {height: 300, minHeight: 240},
                });
                await this.noteEditorView.render();
                overlay.querySelector('[data-nexa-save-note]').disabled = false;
                window.setTimeout(() => overlay.querySelector('.note-editable')?.focus(), 0);
            } catch (error) {
                const message = overlay.querySelector('[data-nexa-note-error]');
                message.textContent = 'The rich-text editor could not be loaded.';
                message.hidden = false;
            }
        }

        handleNoteDialogKeys(event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                this.closeNoteDialog();
                return;
            }
            if (event.key !== 'Tab') return;

            const controls = [...this.noteDialog.querySelectorAll('button:not([disabled]), textarea:not([disabled])')];
            if (!controls.length) return;
            const first = controls[0];
            const last = controls[controls.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }

        async saveContactNote(form = null) {
            form = form || this.noteDialog?.querySelector('[data-nexa-note-form]');
            if (!form || !this.noteEditorView || this.noteSavePending) return;

            this.noteEditorView.fetchToModel();
            const content = String(this.noteEditorModel.get('post') || '').trim();
            const error = form.querySelector('[data-nexa-note-error]');
            if (this.richTextIsEmpty(content)) {
                error.textContent = 'Enter a note before saving.';
                error.hidden = false;
                return;
            }

            const submit = form.querySelector('[data-nexa-save-note]');
            this.noteSavePending = true;
            submit.disabled = true;
            submit.classList.add('is-loading');
            error.hidden = true;
            try {
                const note = await this.createContactStreamNote(`<!-- nexa-contact-note -->\n${content}`);
                this.closeNoteDialog();
                Espo.Ui.success('Note added');
                this.model.trigger('sync');
                await this.loadContactNotes(this.element.querySelector('[data-nexa-contact-workspace]'), note.id);
            } catch (saveError) {
                error.textContent = 'The note could not be saved. Check your access and try again.';
                error.hidden = false;
                submit.disabled = false;
                submit.classList.remove('is-loading');
            } finally {
                this.noteSavePending = false;
            }
        }

        closeNoteDialog() {
            const returnFocus = this.noteDialogReturnFocus;
            if (this.getView('nexaNoteEditor')) this.clearView('nexaNoteEditor');
            this.noteDialog?.remove();
            this.noteDialog = null;
            this.noteDialogReturnFocus = null;
            this.noteEditorView = null;
            this.noteEditorModel = null;
            this.noteSavePending = false;
            returnFocus?.focus?.();
        }

        richTextIsEmpty(content) {
            const container = document.createElement('div');
            container.innerHTML = this.getHelper().sanitizeHtml(String(content || ''));
            return !container.textContent.replace(/\u00a0/g, ' ').trim() && !container.querySelector('img, table, hr');
        }

        async createContactStreamNote(post) {
            const note = await this.getModelFactory().create('Note');
            note.set({
                type: 'Post',
                post,
                parentType: this.model.entityType,
                parentId: this.model.id,
            });
            await note.save(null);
            return note;
        }

        async loadContactNotes(shell = null, newestId = null) {
            const workspace = shell || this.element.querySelector('[data-nexa-contact-workspace]');
            const list = workspace?.querySelector('[data-nexa-note-list]');
            const count = workspace?.querySelector('[data-nexa-note-count]');
            if (!list || !count) return;

            try {
                const payload = await Espo.Ajax.getRequest(`${this.model.entityType}/${encodeURIComponent(this.model.id)}/stream`, {
                    filter: 'posts',
                    maxSize: 100,
                });
                const records = Array.isArray(payload?.list) ? payload.list : [];
                const notes = [];
                const comments = new Map();
                records.forEach(record => {
                    const post = String(record.post || '');
                    const commentMatch = post.match(/^<!-- nexa-note-comment:([A-Za-z0-9_-]+) -->\s*\n?/);
                    if (commentMatch) {
                        const items = comments.get(commentMatch[1]) || [];
                        items.push({...record, content: post.replace(commentMatch[0], '')});
                        comments.set(commentMatch[1], items);
                        return;
                    }
                    const noteMarker = post.match(/^<!-- nexa-contact-note -->\s*\n?/);
                    if (noteMarker) notes.push({...record, content: post.replace(noteMarker[0], '')});
                });

                // Hydrate stream payloads as Note models so the action state uses
                // the same role and ownership checks as authenticated record APIs.
                await Promise.all(notes.map(async note => {
                    const model = await this.getModelFactory().create('Note');
                    model.set(note);
                    model.id = note.id;
                    note.canDelete = this.getAcl().checkModel(model, 'delete') === true;
                }));

                this.contactNoteRecords = notes;
                this.contactNoteComments = comments;
                this.knownContactNoteIds = this.knownContactNoteIds || new Set();
                notes.forEach(note => {
                    if (this.knownContactNoteIds.has(note.id)) return;
                    this.knownContactNoteIds.add(note.id);
                    this.collapsedContactNoteIds?.add(note.id);
                });
                this.renderContactNotes(workspace, newestId);
                this.loadContactNoteOwners(workspace);
            } catch (error) {
                list.innerHTML = `<div class="nexa-note-empty is-error"><span class="fas fa-exclamation-circle" aria-hidden="true"></span><div><strong>Notes unavailable</strong><p>Refresh the page to try loading them again.</p></div></div>`;
            }
        }

        async loadContactNoteOwners(workspace) {
            const noteAuthors = (this.contactNoteRecords || []).map(note => ({
                id: note.createdById,
                name: note.createdByName || 'Removed owner',
                isActive: true,
            })).filter(owner => owner.id);

            if (!this.contactNoteTenantOwners) {
                try {
                    const payload = await Espo.Ajax.getRequest('User', {
                        maxSize: 200,
                        orderBy: 'name',
                        order: 'asc',
                        select: 'id,name,isActive,type',
                    });
                    this.contactNoteTenantOwners = (payload?.list || []).filter(user => {
                        const type = String(user.type || '').toLowerCase();
                        return user.id && user.name && !['system', 'api', 'portal', 'bot'].includes(type);
                    });
                } catch (error) {
                    this.contactNoteTenantOwners = [];
                }
            }

            const owners = new Map();
            [...this.contactNoteTenantOwners, ...noteAuthors].forEach(owner => {
                if (!owners.has(owner.id) || owner.isActive === false) owners.set(owner.id, owner);
            });
            this.contactNoteOwners = [...owners.values()].sort((a, b) => a.name.localeCompare(b.name));
            const knownIds = new Set(this.contactNoteTenantOwners.map(owner => owner.id));
            this.deactivatedContactOwnerIds = new Set(this.contactNoteOwners
                .filter(owner => owner.isActive === false || !knownIds.has(owner.id))
                .map(owner => owner.id));
            this.renderContactNoteOwnerOptions(workspace);
        }

        renderContactNoteOwnerOptions(workspace) {
            const container = workspace?.querySelector('[data-nexa-owner-options]');
            if (!container) return;
            const owners = this.contactNoteOwners || [];
            container.innerHTML = `
                <button type="button" data-nexa-owner-option="all" data-nexa-owner-search="all owners">All owners</button>
                <button type="button" data-nexa-owner-option="me" data-nexa-owner-search="me my activity">Me</button>
                <button type="button" data-nexa-owner-option="deactivated" data-nexa-owner-search="deactivated removed owners">Deactivated and removed owners</button>
                ${owners.length ? '<p>Owners</p>' : ''}
                ${owners.map(owner => `<button type="button" data-nexa-owner-option="${this.escape(owner.id)}" data-nexa-owner-search="${this.escape(owner.name.toLowerCase())}">${this.escape(owner.name)}${owner.isActive === false ? ' (deactivated)' : ''}</button>`).join('')}`;
            container.querySelectorAll('[data-nexa-owner-option]').forEach(button => {
                button.addEventListener('click', () => {
                    const value = button.dataset.nexaOwnerOption;
                    this.contactNoteFilter.owner = value;
                    const labels = {
                        all: 'Activity assigned to',
                        me: 'Me',
                        deactivated: 'Deactivated and removed owners',
                    };
                    workspace.querySelector('[data-nexa-owner-label]').textContent = labels[value] || button.textContent.trim();
                    const search = workspace.querySelector('[data-nexa-owner-search]');
                    search.value = '';
                    container.querySelectorAll('[data-nexa-owner-option]').forEach(option => option.hidden = false);
                    this.closeNoteFilterMenus(workspace);
                    this.renderContactNotes(workspace);
                });
            });
        }

        filteredContactNotes() {
            const filter = this.contactNoteFilter || {query: '', period: 'all', owner: 'all'};
            return (this.contactNoteRecords || []).filter(note => {
                const matchesQuery = !filter.query || `${note.content} ${note.createdByName || ''}`.toLowerCase().includes(filter.query);
                const ownerId = note.createdById || '';
                const currentUserId = this.getUser()?.id || this.getUser()?.get?.('id');
                const matchesOwner = filter.owner === 'all' ||
                    (filter.owner === 'me' && ownerId === currentUserId) ||
                    (filter.owner === 'deactivated' && this.deactivatedContactOwnerIds?.has(ownerId)) ||
                    ownerId === filter.owner;
                return matchesQuery && matchesOwner && this.contactNoteMatchesPeriod(note.createdAt, filter.period);
            });
        }

        contactNoteDate(value) {
            if (!value) return null;
            const normalized = value.includes('T') ? value : `${value.replace(' ', 'T')}Z`;
            const date = new Date(normalized);
            return Number.isNaN(date.getTime()) ? null : date;
        }

        contactNoteMatchesPeriod(value, period) {
            if (!period || period === 'all') return true;
            const date = this.contactNoteDate(value);
            if (!date) return false;
            const now = new Date();
            const day = value => new Date(value.getFullYear(), value.getMonth(), value.getDate());
            const startToday = day(now);
            const startTomorrow = new Date(startToday); startTomorrow.setDate(startTomorrow.getDate() + 1);
            const startYesterday = new Date(startToday); startYesterday.setDate(startYesterday.getDate() - 1);
            const startWeek = new Date(startToday); startWeek.setDate(startWeek.getDate() - ((startWeek.getDay() + 6) % 7));
            const startLastWeek = new Date(startWeek); startLastWeek.setDate(startLastWeek.getDate() - 7);
            const startMonth = new Date(now.getFullYear(), now.getMonth(), 1);
            const startLastMonth = new Date(now.getFullYear(), now.getMonth() - 1, 1);
            const startQuarter = new Date(now.getFullYear(), Math.floor(now.getMonth() / 3) * 3, 1);
            const startLastQuarter = new Date(startQuarter); startLastQuarter.setMonth(startLastQuarter.getMonth() - 3);
            const startYear = new Date(now.getFullYear(), 0, 1);
            const startLastYear = new Date(now.getFullYear() - 1, 0, 1);
            const rolling = days => { const result = new Date(startToday); result.setDate(result.getDate() - (days - 1)); return result; };
            const within = (start, end) => date >= start && date < end;
            const ranges = {
                today: [startToday, startTomorrow], yesterday: [startYesterday, startToday],
                'this-week': [startWeek, startTomorrow], 'last-week': [startLastWeek, startWeek],
                'last-7-days': [rolling(7), startTomorrow], 'this-month': [startMonth, startTomorrow],
                'last-month': [startLastMonth, startMonth], 'last-30-days': [rolling(30), startTomorrow],
                'last-90-days': [rolling(90), startTomorrow], 'last-quarter': [startLastQuarter, startQuarter],
                'this-quarter': [startQuarter, startTomorrow], 'this-year': [startYear, startTomorrow],
                'last-year': [startLastYear, startYear],
            };
            return ranges[period] ? within(...ranges[period]) : true;
        }

        renderContactNotes(workspace = null, newestId = null) {
            workspace = workspace || this.element.querySelector('[data-nexa-contact-workspace]');
            const list = workspace?.querySelector('[data-nexa-note-list]');
            const count = workspace?.querySelector('[data-nexa-note-count]');
            if (!list || !count) return;
            this.clearContactCommentEditors();
            const notes = this.filteredContactNotes();
            const groups = new Map();
            // Pinned notes are deliberately separated before chronological grouping
            // so they remain visible even when their creation month is older.
            const pinned = notes.filter(note => note.isPinned);
            const chronological = notes.filter(note => !note.isPinned);
            if (pinned.length) groups.set('pinned', {label: 'Pinned', notes: pinned, pinned: true});
            chronological.forEach(note => {
                const date = this.contactNoteDate(note.createdAt);
                const key = date ? `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}` : 'unknown';
                const label = date ? new Intl.DateTimeFormat(undefined, {month: 'long', year: 'numeric'}).format(date) : 'Undated';
                if (!groups.has(key)) groups.set(key, {label, notes: []});
                groups.get(key).notes.push(note);
            });
            count.textContent = `${notes.length} ${notes.length === 1 ? 'note' : 'notes'}`;
            list.innerHTML = notes.length
                ? [...groups.values()].map(group => `<section class="nexa-note-month${group.pinned ? ' is-pinned-group' : ''}"><h4>${group.pinned ? '<span class="fas fa-thumbtack" aria-hidden="true"></span>' : ''}${this.escape(group.label)}</h4>${group.notes.map(note => this.contactNoteCard(note, this.contactNoteComments?.get(note.id) || [], note.id === newestId)).join('')}</section>`).join('')
                : `<div class="nexa-note-empty"><span class="far fa-sticky-note" aria-hidden="true"></span><div><strong>No matching notes</strong><p>Try another search, date range or owner.</p></div></div>`;
            const collapse = workspace.querySelector('[data-nexa-collapse-notes]');
            const allCollapsed = notes.length > 0 && notes.every(note => this.collapsedContactNoteIds?.has(note.id));
            if (collapse) collapse.firstChild.textContent = allCollapsed ? 'Expand all ' : 'Collapse all ';
            this.bindContactNoteList(list);
        }

        contactNoteCard(note, comments, isNewest = false) {
            const author = note.createdByName || 'Team member';
            const createdAt = note.createdAt ? this.getDateTime().toDisplay(note.createdAt) : '';
            const collapsed = this.collapsedContactNoteIds?.has(note.id);
            const canDelete = note.canDelete === true;
            const canPin = this.getAcl().checkModel(this.model, 'edit') === true;
            const deleteHelp = "You don't have permission to delete this note. Ask your admin to grant permission.";
            return `<article class="nexa-note-card${isNewest ? ' is-new' : ''}${collapsed ? ' is-collapsed' : ''}${note.isPinned ? ' is-pinned' : ''}" data-nexa-note-id="${this.escape(note.id)}">
                <header>
                    <button class="nexa-note-toggle" type="button" data-nexa-note-toggle aria-expanded="${!collapsed}"><span class="fas fa-chevron-${collapsed ? 'right' : 'down'}" aria-hidden="true"></span>${note.isPinned ? '<span class="fas fa-thumbtack nexa-note-pinned-icon" aria-label="Pinned note"></span>' : ''}<span class="nexa-note-kind">Note</span><span>by <strong>${this.escape(author)}</strong></span>${comments.length ? `<span class="nexa-note-comment-count"><span class="far fa-comment" aria-hidden="true"></span>${comments.length}</span>` : ''}</button>
                    <div class="nexa-note-header-meta"><div class="nexa-note-actions" data-nexa-note-actions${collapsed ? ' hidden' : ''}><button type="button" class="nexa-note-actions-toggle" data-nexa-note-actions-toggle aria-expanded="false">Actions <span class="fas fa-caret-down" aria-hidden="true"></span></button><div class="nexa-note-actions-menu" data-nexa-note-actions-menu hidden><button type="button" data-nexa-note-pin${canPin ? '' : ' disabled aria-disabled="true"'}><span class="fas fa-thumbtack" aria-hidden="true"></span>${note.isPinned ? 'Unpin' : 'Pin'}</button>${canDelete ? '<button type="button" class="is-danger" data-nexa-note-delete><span class="far fa-trash-alt" aria-hidden="true"></span>Delete</button>' : `<span class="nexa-note-action-disabled" data-tooltip="${this.escape(deleteHelp)}" tabindex="0"><button type="button" class="is-danger" disabled aria-disabled="true"><span class="far fa-trash-alt" aria-hidden="true"></span>Delete</button></span>`}</div></div><time>${this.escape(createdAt)}</time></div>
                </header>
                <p class="nexa-note-preview"${collapsed ? '' : ' hidden'}>${this.escape(this.contactNotePreview(note.content))}</p>
                <div class="nexa-note-details" data-nexa-note-details${collapsed ? ' hidden' : ''}>
                    <div class="nexa-note-body">${this.formatNoteContent(note.content)}</div>
                    <footer><button type="button" data-nexa-comment-toggle><span class="far fa-comment" aria-hidden="true"></span><span>Add comment</span></button><span>${comments.length} ${comments.length === 1 ? 'comment' : 'comments'}</span></footer>
                    <div class="nexa-note-comments">${comments.map(comment => this.contactNoteComment(comment)).join('')}</div>
                    <form class="nexa-note-comment-form" data-nexa-comment-form hidden>
                        <div class="nexa-native-rich-editor nexa-comment-editor" data-nexa-comment-editor-host="${this.escape(note.id)}" aria-label="Comment"><div class="nexa-note-editor-loading"><span class="fas fa-circle-notch fa-spin" aria-hidden="true"></span><span>Loading editor</span></div></div>
                        <div><button type="button" class="btn btn-default btn-xs" data-nexa-comment-cancel>Cancel</button><button type="submit" class="btn btn-primary btn-xs">Comment</button></div>
                        <p role="alert" data-nexa-comment-error hidden></p>
                    </form>
                </div>
            </article>`;
        }

        contactNotePreview(content) {
            const value = String(content || '');
            if (/<[a-z][\s\S]*>/i.test(value)) {
                const container = document.createElement('div');
                container.innerHTML = this.getHelper().sanitizeHtml(value);
                return container.textContent.replace(/\u00a0/g, ' ').trim() || 'Empty note';
            }

            return value
                .replace(/\[([^\]]+)]\(([^)]+)\)/g, '$1')
                .replace(/[*_`#>-]/g, '')
                .split(/\r?\n/)
                .map(line => line.trim())
                .find(Boolean) || 'Empty note';
        }

        contactNoteComment(comment) {
            const author = comment.createdByName || 'Team member';
            const createdAt = comment.createdAt ? this.getDateTime().toDisplay(comment.createdAt) : '';
            return `<div class="nexa-note-comment"><div><strong>${this.escape(author)}</strong><time>${this.escape(createdAt)}</time></div><p>${this.formatNoteContent(comment.content)}</p></div>`;
        }

        formatNoteContent(content) {
            const source = String(content || '');
            if (/<[a-z][\s\S]*>/i.test(source)) return this.getHelper().sanitizeHtml(source);

            let value = this.escape(source);
            value = value.replace(/\[([^\]]+)]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');
            value = value.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
            value = value.replace(/_([^_]+)_/g, '<em>$1</em>');
            value = value.replace(/(^|\n)[-*]\s+([^\n]+)/g, '$1<span class="nexa-note-list-item">$2</span>');
            return value.replace(/\r?\n/g, '<br>');
        }

        bindContactNoteList(list) {
            list.querySelectorAll('[data-nexa-note-toggle]').forEach(button => {
                button.addEventListener('click', () => {
                    const card = button.closest('[data-nexa-note-id]');
                    const id = card.dataset.nexaNoteId;
                    if (this.collapsedContactNoteIds.has(id)) this.collapsedContactNoteIds.delete(id);
                    else this.collapsedContactNoteIds.add(id);
                    this.renderContactNotes();
                });
            });
            list.querySelectorAll('[data-nexa-comment-toggle]').forEach(button => {
                button.addEventListener('click', () => {
                    const card = button.closest('[data-nexa-note-id]');
                    const form = card.querySelector('[data-nexa-comment-form]');
                    form.hidden = false;
                    this.mountContactCommentEditor(card.dataset.nexaNoteId, form);
                });
            });
            list.querySelectorAll('[data-nexa-comment-cancel]').forEach(button => {
                button.addEventListener('click', () => {
                    const form = button.closest('[data-nexa-comment-form]');
                    this.clearContactCommentEditor(form.closest('[data-nexa-note-id]').dataset.nexaNoteId);
                    form.hidden = true;
                });
            });
            list.querySelectorAll('[data-nexa-comment-form]').forEach(form => {
                form.addEventListener('submit', event => {
                    event.preventDefault();
                    this.saveNoteComment(form.closest('[data-nexa-note-id]').dataset.nexaNoteId, form);
                });
            });
            list.querySelectorAll('[data-nexa-note-actions-toggle]').forEach(button => {
                button.addEventListener('click', event => {
                    event.stopPropagation();
                    const menu = button.parentElement.querySelector('[data-nexa-note-actions-menu]');
                    const opening = menu.hidden;
                    list.querySelectorAll('[data-nexa-note-actions-menu]').forEach(item => item.hidden = true);
                    list.querySelectorAll('[data-nexa-note-actions-toggle]').forEach(item => item.setAttribute('aria-expanded', 'false'));
                    menu.hidden = !opening;
                    button.setAttribute('aria-expanded', String(opening));
                });
            });
            list.querySelectorAll('[data-nexa-note-pin]').forEach(button => {
                button.addEventListener('click', () => {
                    const noteId = button.closest('[data-nexa-note-id]').dataset.nexaNoteId;
                    const note = (this.contactNoteRecords || []).find(item => item.id === noteId);
                    this.setContactNotePinned(noteId, !note?.isPinned, button);
                });
            });
            list.querySelectorAll('[data-nexa-note-delete]').forEach(button => {
                button.addEventListener('click', () => this.openNoteDeleteDialog(button.closest('[data-nexa-note-id]').dataset.nexaNoteId));
            });
            if (this.noteActionsDocumentHandler) document.removeEventListener('click', this.noteActionsDocumentHandler);
            this.noteActionsDocumentHandler = event => {
                if (event.target.closest('[data-nexa-note-actions]')) return;
                list.querySelectorAll('[data-nexa-note-actions-menu]').forEach(menu => menu.hidden = true);
                list.querySelectorAll('[data-nexa-note-actions-toggle]').forEach(button => button.setAttribute('aria-expanded', 'false'));
            };
            document.addEventListener('click', this.noteActionsDocumentHandler);
        }

        async setContactNotePinned(noteId, pinned, button) {
            if (!noteId || button?.disabled || button?.dataset.saving === 'true') return;
            button.dataset.saving = 'true';
            button.disabled = true;
            try {
                const path = `Note/${encodeURIComponent(noteId)}/pin`;
                if (pinned) await Espo.Ajax.postRequest(path);
                else await Espo.Ajax.deleteRequest(path);
                const note = (this.contactNoteRecords || []).find(item => item.id === noteId);
                if (note) note.isPinned = pinned;
                this.renderContactNotes();
                Espo.Ui.success(pinned ? 'Note pinned' : 'Note unpinned');
            } catch (error) {
                button.disabled = false;
                button.dataset.saving = 'false';
                Espo.Ui.error('The note could not be updated. Check your access and try again.');
            }
        }

        openNoteDeleteDialog(noteId) {
            const note = (this.contactNoteRecords || []).find(item => item.id === noteId);
            if (!note?.canDelete) return;
            this.closeNoteDeleteDialog();
            const overlay = document.createElement('div');
            overlay.className = 'nexa-note-delete-overlay';
            overlay.dataset.nexaNoteDeleteDialog = 'true';
            overlay.innerHTML = `<section class="nexa-note-delete-dialog" role="dialog" aria-modal="true" aria-labelledby="nexa-note-delete-title"><header><div><p>Delete note</p><h2 id="nexa-note-delete-title">Delete this note?</h2></div><button type="button" class="nexa-dialog-close" data-nexa-note-delete-close aria-label="Close"><span class="fas fa-times" aria-hidden="true"></span></button></header><div class="nexa-note-delete-content"><p>This note will be removed from the contact timeline. This action cannot be undone from this page.</p><p class="nexa-note-delete-error" role="alert" hidden></p></div><footer><button type="button" class="btn btn-default" data-nexa-note-delete-cancel>Cancel</button><button type="button" class="btn btn-danger" data-nexa-note-delete-confirm><span class="far fa-trash-alt" aria-hidden="true"></span><span>Delete note</span></button></footer></section>`;
            document.body.append(overlay);
            this.noteDeleteDialog = overlay;
            const close = () => this.closeNoteDeleteDialog();
            overlay.querySelector('[data-nexa-note-delete-close]').addEventListener('click', close);
            overlay.querySelector('[data-nexa-note-delete-cancel]').addEventListener('click', close);
            overlay.addEventListener('click', event => { if (event.target === overlay) close(); });
            overlay.addEventListener('keydown', event => { if (event.key === 'Escape') close(); });
            overlay.querySelector('[data-nexa-note-delete-confirm]').addEventListener('click', event => this.deleteContactNote(noteId, event.currentTarget));
            window.setTimeout(() => overlay.querySelector('[data-nexa-note-delete-cancel]')?.focus(), 0);
        }

        closeNoteDeleteDialog() {
            this.noteDeleteDialog?.remove();
            this.noteDeleteDialog = null;
        }

        async deleteContactNote(noteId, button) {
            if (!noteId || button.dataset.saving === 'true') return;
            const error = this.noteDeleteDialog?.querySelector('.nexa-note-delete-error');
            button.dataset.saving = 'true';
            button.disabled = true;
            button.classList.add('is-loading');
            try {
                await Espo.Ajax.deleteRequest(`Note/${encodeURIComponent(noteId)}`);
                this.contactNoteRecords = (this.contactNoteRecords || []).filter(note => note.id !== noteId);
                this.contactNoteComments?.delete(noteId);
                this.collapsedContactNoteIds?.delete(noteId);
                this.closeNoteDeleteDialog();
                this.renderContactNotes();
                Espo.Ui.success('Note deleted');
            } catch (deleteError) {
                if (error) {
                    error.textContent = 'The note could not be deleted. Check your permission and try again.';
                    error.hidden = false;
                }
                button.dataset.saving = 'false';
                button.disabled = false;
                button.classList.remove('is-loading');
            }
        }

        async mountContactCommentEditor(noteId, form) {
            this.contactCommentEditors = this.contactCommentEditors || new Map();
            const existing = this.contactCommentEditors.get(noteId);
            if (existing) {
                form.querySelector('.note-editable')?.focus();
                return;
            }

            const submit = form.querySelector('button[type="submit"]');
            submit.disabled = true;
            const key = `nexaCommentEditor-${noteId}`;
            try {
                const model = await this.getModelFactory().create('Note');
                if (!form.isConnected || form.hidden) return;
                const view = await this.createView(key, 'views/fields/wysiwyg', {
                    fullSelector: `[data-nexa-comment-editor-host="${noteId}"]`,
                    model,
                    name: 'post',
                    mode: 'edit',
                    params: {height: 150, minHeight: 120},
                });
                await view.render();
                this.contactCommentEditors.set(noteId, {key, model, view});
                submit.disabled = false;
                window.setTimeout(() => form.querySelector('.note-editable')?.focus(), 0);
            } catch (error) {
                const message = form.querySelector('[data-nexa-comment-error]');
                message.textContent = 'The rich-text editor could not be loaded.';
                message.hidden = false;
            }
        }

        clearContactCommentEditor(noteId) {
            const entry = this.contactCommentEditors?.get(noteId);
            if (!entry) return;
            if (this.getView(entry.key)) this.clearView(entry.key);
            this.contactCommentEditors.delete(noteId);
        }

        clearContactCommentEditors() {
            if (!this.contactCommentEditors) return;
            [...this.contactCommentEditors.keys()].forEach(noteId => this.clearContactCommentEditor(noteId));
        }

        async saveNoteComment(noteId, form) {
            if (form.dataset.saving === 'true') return;
            const editor = this.contactCommentEditors?.get(noteId);
            if (!editor) return;
            editor.view.fetchToModel();
            const content = String(editor.model.get('post') || '').trim();
            const error = form.querySelector('[data-nexa-comment-error]');
            if (this.richTextIsEmpty(content)) {
                error.textContent = 'Enter a comment before saving.';
                error.hidden = false;
                return;
            }

            form.dataset.saving = 'true';
            form.querySelector('button[type="submit"]').disabled = true;
            error.hidden = true;
            try {
                await this.createContactStreamNote(`<!-- nexa-note-comment:${noteId} -->\n${content}`);
                this.model.trigger('sync');
                await this.loadContactNotes();
            } catch (saveError) {
                error.textContent = 'The comment could not be saved.';
                error.hidden = false;
                form.dataset.saving = 'false';
                form.querySelector('button[type="submit"]').disabled = false;
            }
        }

        async loadProfileImage(container) {
            const id = this.model.get('profileImageId');
            if (!id || !container) return;
            try {
                const payload = await Espo.Ajax.getRequest(`Nexa/contact-profile-image/${encodeURIComponent(id)}`);
                if (!payload?.data || !payload?.mimeType || this.model.get('profileImageId') !== id) return;
                const binary = atob(payload.data);
                const bytes = Uint8Array.from(binary, character => character.charCodeAt(0));
                this.releaseProfileImage();
                this.profileImageUrl = URL.createObjectURL(new Blob([bytes], {type: payload.mimeType}));
                const image = document.createElement('img');
                image.src = this.profileImageUrl;
                image.alt = '';
                container.replaceChildren(image);
            } catch (error) {
                // Initials remain available when a protected portrait is unavailable.
            }
        }

        releaseProfileImage() {
            if (this.profileImageUrl) URL.revokeObjectURL(this.profileImageUrl);
            this.profileImageUrl = null;
        }

        optionLabel(field, value) {
            if (value === null || value === undefined || value === '') return 'Not recorded';
            return this.getLanguage().translateOption(value, field, 'Contact') || value;
        }

        displayValue(value) {
            if (value === null || value === undefined || value === '') return 'Not recorded';
            if (typeof value === 'boolean') return value ? 'Yes' : 'No';
            return String(value);
        }

        formatAddress() {
            return [
                this.model.get('addressStreet'),
                this.model.get('addressCity'),
                this.model.get('addressState'),
                this.model.get('addressPostalCode'),
                this.model.get('addressCountry'),
            ].filter(value => value !== null && value !== undefined && String(value).trim() !== '').join(', ');
        }

        resolvedPreferredTimeZone() {
            const contactValue = this.model.get('preferredTimeZone');
            if (contactValue) return contactValue;

            const preferenceValue = typeof this.getPreferences === 'function' ? this.getPreferences().get('timeZone') : null;
            const configuredValue = this.getConfig().get('timeZone');
            return preferenceValue || configuredValue || 'Default timezone';
        }

        leadStatusBadge() {
            const value = this.model.get('leadStatus');
            if (!value) return '<span class="nexa-record-empty">Not recorded</span>';

            // Stable enum values share the same presentation contract as the Contact table.
            const statusClasses = {
                New: 'new',
                Open: 'open',
                InProgress: 'in-progress',
                OpenDeal: 'open-deal',
                Unqualified: 'unqualified',
                AttemptedToContact: 'attempted',
                Connected: 'connected',
                BadTiming: 'bad-timing',
            };
            const label = this.optionLabel('leadStatus', value);
            return `<span class="nexa-lead-status nexa-lead-status--${statusClasses[value] || 'other'}">${this.escape(label)}</span>`;
        }

        ownerLink() {
            const id = this.model.get('assignedUserId');
            const name = this.model.get('assignedUserName');
            if (!id || !name) return '<span class="nexa-record-empty">Not recorded</span>';

            return `<a class="nexa-contact-owner-link" href="#User/view/${encodeURIComponent(id)}">${this.escape(name)}</a>`;
        }

        formatCreatedAt() {
            const value = this.model.get('createdAt');
            if (!value) return '';

            return this.getDateTime().toDisplay(value);
        }

        createdAudit() {
            const date = this.formatCreatedAt();
            const id = this.model.get('createdById');
            const name = this.model.get('createdByName');

            if (!date) return '<span class="nexa-record-empty">Not recorded</span>';

            const creator = id && name
                ? `<span class="nexa-created-by">by <a class="nexa-contact-creator-link" href="#User/view/${encodeURIComponent(id)}">${this.escape(name)}</a></span>`
                : '';

            return `<span class="nexa-created-audit"><span>${this.escape(date)}</span>${creator}</span>`;
        }

        socialProfilesCard() {
            const profiles = [
                ['LinkedIn', 'linkedinUrl', 'linkedin.svg'],
                ['Facebook', 'facebookUrl', 'facebook.svg'],
                ['Instagram', 'instagramUrl', 'instagram.svg'],
                ['X / Twitter', 'xUrl', 'x.svg'],
                ['TikTok', 'tiktokUrl', 'tiktok.svg'],
            ].map(([label, field, asset]) => {
                const profile = this.normaliseExternalProfile(this.model.get(field));
                if (!profile) return '';

                const tooltip = `${label}: ${profile.display}`;
                const iconUrl = `${this.getBasePath()}client/custom/img/social/${asset}`;
                return `
                    <a class="nexa-social-profile-link" href="${this.escape(profile.href)}"
                       target="_blank" rel="noopener noreferrer" role="listitem"
                       aria-label="Open ${this.escape(label)} profile in a new tab"
                       data-tooltip="${this.escape(tooltip)}">
                        <img src="${this.escape(iconUrl)}" alt="" aria-hidden="true">
                    </a>
                `;
            });

            const skypeName = String(this.model.get('skypeName') || '').trim().replace(/^@/, '');
            if (skypeName) {
                profiles.push(`
                    <a class="nexa-social-profile-link" href="skype:${encodeURIComponent(skypeName)}?chat"
                       target="_blank" rel="noopener noreferrer" role="listitem"
                       aria-label="Open Skype contact ${this.escape(skypeName)}"
                       data-tooltip="Skype: ${this.escape(skypeName)}">
                        <img src="${this.escape(`${this.getBasePath()}client/custom/img/social/skype.svg`)}" alt="" aria-hidden="true">
                    </a>
                `);
            }

            const links = profiles.filter(Boolean).join('');
            const body = links
                ? `<div class="nexa-social-profile-grid" role="list">${links}</div>`
                : '<span class="nexa-record-empty">No social profiles recorded</span>';

            return `
                <section class="nexa-sidebar-card nexa-social-profiles-card">
                    <h3>Social profiles</h3>
                    ${body}
                </section>
            `;
        }

        normaliseExternalProfile(value) {
            if (!value) return null;

            const raw = String(value).trim();
            if (!raw) return null;

            const href = /^https?:\/\//i.test(raw) ? raw : `https://${raw.replace(/^\/+/, '')}`;
            let display = raw.replace(/^https?:\/\//i, '').replace(/^www\./i, '').replace(/\/$/, '');
            try {
                const parsed = new URL(href);
                if (!['http:', 'https:'].includes(parsed.protocol)) return null;

                display = `${parsed.hostname.replace(/^www\./i, '')}${parsed.pathname === '/' ? '' : parsed.pathname}${parsed.search}`.replace(/\/$/, '');
            } catch (error) {
                return null;
            }

            return {href, display};
        }

        externalLink(value) {
            const profile = this.normaliseExternalProfile(value);
            if (!profile) return '<span class="nexa-record-empty">Not recorded</span>';

            return `<a class="nexa-external-profile-link" href="${this.escape(profile.href)}" target="_blank" rel="noopener noreferrer">${this.escape(profile.display)}</a>`;
        }

        escape(value) {
            const node = document.createElement('span');
            node.textContent = String(value);
            return node.innerHTML;
        }
    };
});
