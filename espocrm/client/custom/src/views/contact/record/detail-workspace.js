define('custom:views/contact/record/detail-workspace', ['crm:views/contact/record/detail', 'helpers/record-modal', 'custom:views/contact/record/twilio-call-controller', 'custom:views/call/caller-id-modal'], (Dep, RecordModalHelper, TwilioCallController, CallerIdVerifyModal) => {
    return class extends Dep {
        setup() {
            super.setup();
            document.body.classList.add('nexa-contact-detail-page');
            this.once('remove', () => {
                document.body.classList.remove('nexa-contact-detail-page');
                this.cancelInlineDetailEdit();
                this.cancelTaskFieldEdit();
                this.cancelMeetingFieldEdit?.();
                this.cancelNoteFieldEdit?.();
                this.closeCustomerCommandMenu();
                this.closeInteractionDialog();
                this.closeCallDialog();
                this.closeCallPickerDialog();
                this.callController?.destroy();
                this.closeNoteDialog();
                this.closeTaskDialog();
                this.closeCallDeleteDialog();
                this.cancelCallFieldEdit?.();
                this.clearContactCommentEditors();
                this.releaseProfileImage();
                if (this.actionMenuDocumentHandler) {
                    document.removeEventListener('click', this.actionMenuDocumentHandler);
                }
                if (this.notesFilterDocumentHandler) {
                    document.removeEventListener('click', this.notesFilterDocumentHandler);
                }
                if (this.tasksFilterDocumentHandler) {
                    document.removeEventListener('click', this.tasksFilterDocumentHandler);
                }
                if (this.meetingsFilterDocumentHandler) {
                    document.removeEventListener('click', this.meetingsFilterDocumentHandler);
                }
                if (this.callsFilterDocumentHandler) {
                    document.removeEventListener('click', this.callsFilterDocumentHandler);
                }
                if (this.noteActionsDocumentHandler) {
                    document.removeEventListener('click', this.noteActionsDocumentHandler);
                }
                if (this.meetingActionsDocumentHandler) {
                    document.removeEventListener('click', this.meetingActionsDocumentHandler);
                }
                if (this.callActionsDocumentHandler) {
                    document.removeEventListener('click', this.callActionsDocumentHandler);
                }
                if (this.taskActionsDocumentHandler) {
                    document.removeEventListener('click', this.taskActionsDocumentHandler);
                }
                if (this.emailActionsDocumentHandler) {
                    document.removeEventListener('click', this.emailActionsDocumentHandler);
                }
                if (this.activityActionsDocumentHandler) {
                    document.removeEventListener('click', this.activityActionsDocumentHandler);
                }
                this.contactActivityObserver?.disconnect();
                this.stopContactEmailPolling();
                this.closeNoteDeleteDialog();
                this.closeMeetingDeleteDialog();
                this.closeTaskDeleteDialog();
                this.closeActivityDeleteDialog();
                this.closeEmailDeleteDialog();
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
            this.loadContactTasks(shell);
            this.loadContactMeetings(shell);
            this.loadContactCalls(shell);
            this.loadContactEmails(shell);
            this.startContactEmailPolling(shell);
            this.loadContactCommunicationActivities(shell);
            this.loadContactPipelineValue(shell);
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
                            <button type="button" class="nexa-contact-primary-email" data-nexa-compose-email aria-label="Compose email to this contact"></button>
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
                        ${this.tabButton('tasks', 'Tasks')}
                        ${this.tabButton('meetings', 'Meetings')}
                        ${this.tabButton('calls', 'Calls')}
                        ${this.tabButton('email', 'Email')}
                    </nav>
                    <div class="nexa-customer-tab-content">
                        ${this.overviewPanel()}
                        ${this.activityPanel()}
                        ${this.notesPanel()}
                        ${this.tasksPanel()}
                        ${this.meetingsPanel()}
                        ${this.callsPanel()}
                        ${this.emailsPanel()}
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
            if (this.model.get('doNotContact')) {
                const badge = document.createElement('span');
                const channels = String(this.model.get('doNotContactChannels') || '').split(',').filter(Boolean);
                badge.className = 'nexa-do-not-contact-badge nexa-do-not-contact-badge--profile';
                badge.setAttribute('role', 'img');
                badge.setAttribute('aria-label', 'Do not contact');
                badge.innerHTML = '<span class="fas fa-ban" aria-hidden="true"></span><span>Do not contact</span>';
                badge.title = channels.length ? `Restricted channels: ${channels.join(', ')}` : 'Do not contact';
                shell.querySelector('.nexa-contact-heading').appendChild(badge);
            }
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
                if (panel) {
                    panel.dataset.nexaActivitySource = name;
                    activity.append(panel);
                }
            });
            ['accounts', 'opportunities', 'cases', 'documents', 'targetLists'].forEach(name => {
                const panel = bottom?.querySelector(`[data-name="${name}"]`);
                if (panel) associations.append(panel);
            });

            nativeRecord.classList.add('nexa-native-record-host');
            this.observeContactActivity(activity);
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
                <div class="nexa-tab-heading"><div><p class="nexa-contact-eyebrow">Customer 360</p><h3>Overview</h3></div><p>Everything CRM, marketing and service know about this customer, in one place.</p></div>
                ${this.communicationRestrictionAlert()}
                <div class="nexa-highlight-grid">
                    ${this.highlight('Lifecycle', this.optionLabel('lifecycleStage', this.model.get('lifecycleStage')), 'fas fa-route')}
                    ${this.highlight('Lead score', this.model.get('leadScore'), 'fas fa-star')}
                    ${this.highlight('Marketing status', this.optionLabel('marketingStatus', this.model.get('marketingStatus')), 'fas fa-bullhorn')}
                    ${this.highlight('Last activity', this.model.get('modifiedAt'), 'far fa-clock')}
                </div>
                <div class="nexa-overview-insights" data-nexa-overview-insights>
                    <div class="nexa-note-loading"><span class="fas fa-circle-notch fa-spin" aria-hidden="true"></span><span>Loading insights</span></div>
                </div>
            </section>`;
        }

        async loadContactPipelineValue(workspace = null) {
            try {
                const payload = await Espo.Ajax.getRequest(`Contact/${encodeURIComponent(this.model.id)}/opportunities`, {
                    maxSize: 200,
                    select: 'amount,amountCurrency,stage,name',
                });
                const closedStages = ['Closed Won', 'Closed Lost'];
                const open = (payload?.list || []).filter(item => !closedStages.includes(item.stage));
                this.contactOpenPipelineValue = open.reduce((sum, item) => sum + (Number(item.amount) || 0), 0);
                this.contactOpenPipelineCount = open.length;
                this.contactOpenPipelineCurrency = open[0]?.amountCurrency || this.getConfig().get('defaultCurrency') || 'USD';
            } catch (error) {
                this.contactOpenPipelineValue = null;
                this.contactOpenPipelineCount = 0;
            }
            this.renderContactOverviewInsights(workspace);
        }

        formatCurrencyAmount(amount, currency) {
            try {
                return new Intl.NumberFormat(undefined, {
                    style: 'currency', currency: currency || 'USD', maximumFractionDigits: 0,
                }).format(amount);
            } catch (error) {
                return `${currency || ''} ${Math.round(amount).toLocaleString()}`.trim();
            }
        }

        // One combined list of this contact's meetings/tasks/calls, each
        // reduced to a single calendar date - tasks use their due date
        // (dateEnd), meetings/calls use their start date (dateStart).
        // Colors match EspoCRM's own core Calendar module exactly
        // (Meeting/Call/Task), so this reads as "the same calendar", just
        // scoped to one contact instead of a user's whole schedule.
        contactCalendarEvents() {
            const events = [];

            (this.contactMeetingRecords || []).forEach(meeting => {
                if (!meeting.dateStart) return;
                events.push({
                    id: meeting.id, type: 'Meeting', name: meeting.name || 'Untitled meeting',
                    date: this.contactNoteDate(meeting.dateStart), rawDate: meeting.dateStart, status: meeting.status,
                    icon: 'far fa-calendar', color: '#558BBD',
                });
            });
            (this.contactCallRecords || []).forEach(call => {
                if (!call.dateStart) return;
                events.push({
                    id: call.id, type: 'Call', name: call.name || 'Untitled call',
                    date: this.contactNoteDate(call.dateStart), rawDate: call.dateStart, status: call.status,
                    icon: 'fas fa-phone', color: '#CF605D',
                });
            });
            (this.contactTaskRecords || []).forEach(task => {
                if (!task.dateEnd) return;
                events.push({
                    id: task.id, type: 'Task', name: task.name || 'Untitled task',
                    date: this.contactNoteDate(task.dateEnd), rawDate: task.dateEnd, status: task.status,
                    icon: 'far fa-check-square', color: '#70c173',
                    overdue: this.taskIsOverdue(task),
                });
            });

            return events.filter(event => event.date);
        }

        dateKey(date) {
            return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
        }

        // "0:00" on a parsed date almost always means the source field had no
        // time component (an all-day task due-date, for instance) - skip the
        // time prefix for those, same as a real calendar would for an all-day item.
        formatEventTime(date) {
            if (date.getHours() === 0 && date.getMinutes() === 0) return '';
            return new Intl.DateTimeFormat(undefined, {hour: '2-digit', minute: '2-digit', hour12: false}).format(date);
        }

        renderContactMiniCalendar(workspace) {
            const container = workspace?.querySelector('[data-nexa-mini-calendar]');
            if (!container) return;

            this.contactCalendarMonth = this.contactCalendarMonth || new Date(new Date().getFullYear(), new Date().getMonth(), 1);
            const monthDate = this.contactCalendarMonth;
            const events = this.contactCalendarEvents();

            const byDay = new Map();
            events.forEach(event => {
                const key = this.dateKey(event.date);
                if (!byDay.has(key)) byDay.set(key, []);
                byDay.get(key).push(event);
            });

            const monthLabel = new Intl.DateTimeFormat(undefined, {month: 'long', year: 'numeric'}).format(monthDate);
            const firstOfMonth = new Date(monthDate.getFullYear(), monthDate.getMonth(), 1);
            // Monday-first week, matching EspoCRM's own Calendar module.
            const startOffset = (firstOfMonth.getDay() + 6) % 7;
            const daysInMonth = new Date(monthDate.getFullYear(), monthDate.getMonth() + 1, 0).getDate();
            const todayKey = this.dateKey(new Date());
            const maxVisiblePerDay = 4;

            const cells = [];
            for (let index = 0; index < startOffset; index += 1) {
                cells.push('<div class="nexa-mini-calendar-cell is-outside" aria-hidden="true"></div>');
            }
            for (let day = 1; day <= daysInMonth; day += 1) {
                const date = new Date(monthDate.getFullYear(), monthDate.getMonth(), day);
                const key = this.dateKey(date);
                const dayEvents = (byDay.get(key) || []).sort((left, right) => left.date.getTime() - right.date.getTime());
                const isToday = key === todayKey;

                const visible = dayEvents.slice(0, maxVisiblePerDay);
                const overflowCount = dayEvents.length - visible.length;
                const pills = visible.map(event => {
                    const time = this.formatEventTime(event.date);
                    return `
                        <a class="nexa-mini-calendar-pill${event.overdue ? ' is-overdue' : ''}" style="background:${event.color}"
                            href="#${event.type}/view/${event.id}" title="${this.escape(event.name)}">${time ? `${this.escape(time)} ` : ''}${this.escape(event.name)}</a>`;
                }).join('');
                const overflowLabel = overflowCount > 0
                    ? `<span class="nexa-mini-calendar-more">+${overflowCount} more</span>`
                    : '';

                cells.push(`
                    <div class="nexa-mini-calendar-cell${isToday ? ' is-today' : ''}">
                        <span class="nexa-mini-calendar-daynum">${day}</span>
                        <div class="nexa-mini-calendar-pills">${pills}${overflowLabel}</div>
                    </div>`);
            }

            const weekdayLabels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

            container.innerHTML = `
                <div class="nexa-mini-calendar-header">
                    <button type="button" class="btn btn-default btn-icon btn-sm" data-nexa-calendar-prev aria-label="Previous month"><span class="fas fa-chevron-left" aria-hidden="true"></span></button>
                    <strong>${this.escape(monthLabel)}</strong>
                    <button type="button" class="btn btn-default btn-icon btn-sm" data-nexa-calendar-next aria-label="Next month"><span class="fas fa-chevron-right" aria-hidden="true"></span></button>
                </div>
                <div class="nexa-mini-calendar-weekdays">
                    ${weekdayLabels.map(label => `<span>${label}</span>`).join('')}
                </div>
                <div class="nexa-mini-calendar-grid">${cells.join('')}</div>`;

            container.querySelector('[data-nexa-calendar-prev]').addEventListener('click', () => {
                this.contactCalendarMonth = new Date(monthDate.getFullYear(), monthDate.getMonth() - 1, 1);
                this.renderContactMiniCalendar(workspace);
            });
            container.querySelector('[data-nexa-calendar-next]').addEventListener('click', () => {
                this.contactCalendarMonth = new Date(monthDate.getFullYear(), monthDate.getMonth() + 1, 1);
                this.renderContactMiniCalendar(workspace);
            });
        }

        renderContactOverviewInsights(workspace = null) {
            workspace = workspace || this.element.querySelector('[data-nexa-contact-workspace]');
            const container = workspace?.querySelector('[data-nexa-overview-insights]');
            if (!container) return;

            const tasks = this.contactTaskRecords || [];
            const openTasks = tasks.filter(task => !['Completed', 'Canceled'].includes(task.status));
            const overdueCount = openTasks.filter(task => this.taskIsOverdue(task)).length;
            const openTasksValue = openTasks.length
                ? `${openTasks.length} open${overdueCount ? ` (${overdueCount} overdue)` : ''}`
                : 'None open';

            const meetings = this.contactMeetingRecords || [];
            const now = Date.now();
            const nextMeeting = meetings
                .filter(meeting => meeting.status === 'Planned' && meeting.dateStart && new Date(meeting.dateStart).getTime() >= now)
                .sort((left, right) => new Date(left.dateStart).getTime() - new Date(right.dateStart).getTime())[0];
            const nextMeetingValue = nextMeeting
                ? `${nextMeeting.name} — ${this.getDateTime().toDisplay(nextMeeting.dateStart)}`
                : 'None scheduled';

            const pipelineValue = this.contactOpenPipelineValue === undefined
                ? 'Loading…'
                : this.contactOpenPipelineValue === null
                    ? 'Unavailable'
                    : this.contactOpenPipelineValue > 0
                        ? `${this.formatCurrencyAmount(this.contactOpenPipelineValue, this.contactOpenPipelineCurrency)} · ${this.contactOpenPipelineCount} open`
                        : 'No open deals';

            container.innerHTML = `
                <div class="nexa-highlight-grid nexa-overview-next-grid">
                    ${this.highlight('Open tasks', openTasksValue, 'far fa-check-square', overdueCount ? 'danger' : null)}
                    ${this.highlight('Next meeting', nextMeetingValue, 'far fa-calendar')}
                    ${this.highlight('Open pipeline', pipelineValue, 'fas fa-sack-dollar')}
                </div>
                <section class="nexa-context-card nexa-mini-calendar-card">
                    <h4>Calendar<span>Meetings, tasks &amp; calls</span></h4>
                    <div class="nexa-mini-calendar" data-nexa-mini-calendar></div>
                </section>`;

            this.renderContactMiniCalendar(workspace);
        }

        communicationRestrictionAlert() {
            if (!this.model.get('doNotContact')) return '';

            const channelLabels = {email: 'Email', phone: 'Phone calls', sms: 'SMS', whatsapp: 'WhatsApp', postal: 'Postal mail'};
            const reasonLabels = {
                contact_request: 'Contact requested no communication', unsubscribed: 'Unsubscribed',
                invalid_details: 'Invalid contact details', legal_compliance: 'Legal or compliance requirement',
                complaint: 'Complaint', consent_restored: 'Consent restored',
                correction: 'Previous restriction was incorrect', other: 'Other',
            };
            const channels = String(this.model.get('doNotContactChannels') || '').split(',').filter(Boolean)
                .map(channel => channelLabels[channel] || channel);
            const reason = reasonLabels[this.model.get('doNotContactReason')] || 'Communication restricted';
            const note = this.model.get('doNotContactNote');

            return `<aside class="nexa-communication-alert" role="alert">
                <span class="nexa-communication-alert-icon"><span class="fas fa-ban" aria-hidden="true"></span></span>
                <div><strong>Do not contact</strong><p>${this.escape(channels.join(', ') || 'Restricted channels')} &middot; ${this.escape(reason)}</p>${note ? `<p class="nexa-communication-alert-note">${this.escape(note)}</p>` : ''}</div>
            </aside>`;
        }

        activityPanel() {
            return `<section class="nexa-customer-tab-panel nexa-activity-workspace" data-nexa-tab-panel="activity" role="tabpanel" hidden>
                <div class="nexa-activity-toolbar">
                    <div class="nexa-activity-toolbar-primary">
                        <label class="nexa-activity-search"><span class="sr-only">Search activities</span><input type="search" data-nexa-activity-search placeholder="Search activities"><span class="fas fa-search" aria-hidden="true"></span></label>
                        <div class="nexa-activity-toolbar-actions"><span class="nexa-activity-count" data-nexa-activity-count aria-live="polite">Loading activities</span><button type="button" class="nexa-collapse-activities" data-nexa-collapse-activities>Collapse all <span class="fas fa-caret-down" aria-hidden="true"></span></button></div>
                    </div>
                    <button type="button" class="btn btn-default nexa-activity-filter-toggle" data-nexa-activity-filter-toggle aria-expanded="true"><span class="fas fa-sliders-h" aria-hidden="true"></span><span>Filters</span></button>
                    <div class="nexa-activity-filters" data-nexa-activity-filters>
                        <label><span>Activity type</span><select class="form-control" data-nexa-activity-type>
                            <option value="all">All activities</option><option value="call">Calls</option><option value="meeting">Meetings</option><option value="email">Emails</option><option value="task">Tasks</option><option value="note">Notes</option><option value="preference">Communication preferences</option><option value="other">Other activity</option>
                        </select></label>
                        <label><span>Date range</span><select class="form-control" data-nexa-activity-period>${this.notePeriodOptions().map(([value, label]) => `<option value="${value}">${label}</option>`).join('')}</select></label>
                    </div>
                </div>
                <div class="nexa-activity-list" data-nexa-activity-list aria-live="polite"><div class="nexa-note-loading"><span class="fas fa-circle-notch fa-spin" aria-hidden="true"></span><span>Loading activities</span></div></div>
                <div class="nexa-native-activity-source" data-nexa-activity-panels aria-hidden="true"></div>
                <div class="nexa-activity-empty" data-nexa-activity-empty hidden><span class="far fa-calendar-times" aria-hidden="true"></span><div><strong>No matching activities</strong><p>Try another search, activity type or date range.</p></div></div>
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

        tasksPanel() {
            return `<section class="nexa-customer-tab-panel nexa-notes-workspace" data-nexa-tab-panel="tasks" role="tabpanel" hidden>
                <div class="nexa-notes-toolbar">
                    <div class="nexa-notes-toolbar-primary">
                        <label class="nexa-notes-search"><span class="sr-only">Search tasks</span><input type="search" data-nexa-tasks-search placeholder="Search tasks"><span class="fas fa-search" aria-hidden="true"></span></label>
                        <div class="nexa-notes-toolbar-actions">
                            <button type="button" class="nexa-collapse-notes" data-nexa-collapse-tasks>Collapse all <span class="fas fa-caret-down" aria-hidden="true"></span></button>
                            <button type="button" class="btn btn-default" data-nexa-create-task><span class="far fa-check-square" aria-hidden="true"></span><span>Create a task</span></button>
                        </div>
                    </div>
                    <button type="button" class="btn btn-default nexa-notes-filter-toggle" data-nexa-tasks-filter-toggle aria-expanded="true"><span class="fas fa-sliders-h" aria-hidden="true"></span><span>Filters</span></button>
                    <div class="nexa-notes-filters" data-nexa-tasks-filters>
                        <div class="nexa-note-filter" data-nexa-task-period-filter>
                            <button type="button" data-nexa-task-period-toggle aria-haspopup="true" aria-expanded="false"><span data-nexa-task-period-label>All time</span><span class="fas fa-caret-down" aria-hidden="true"></span></button>
                            <div class="nexa-note-filter-menu nexa-note-period-menu" data-nexa-task-period-menu hidden>
                                ${this.notePeriodOptions().map(([value, label]) => `<button type="button" data-nexa-task-period="${value}">${label}</button>`).join('')}
                            </div>
                        </div>
                        <div class="nexa-note-filter" data-nexa-task-owner-filter>
                            <button type="button" data-nexa-task-owner-toggle aria-haspopup="true" aria-expanded="false"><span data-nexa-task-owner-label>Activity assigned to</span><span class="fas fa-caret-down" aria-hidden="true"></span></button>
                            <div class="nexa-note-filter-menu nexa-note-owner-menu" data-nexa-task-owner-menu hidden>
                                <label><span class="fas fa-search" aria-hidden="true"></span><input type="search" data-nexa-task-owner-search placeholder="Search owners" aria-label="Search owners"></label>
                                <div data-nexa-task-owner-options></div>
                            </div>
                        </div>
                        <div class="nexa-note-filter" data-nexa-task-status-filter>
                            <button type="button" data-nexa-task-status-toggle aria-haspopup="true" aria-expanded="false"><span data-nexa-task-status-label>Task stage</span><span class="fas fa-caret-down" aria-hidden="true"></span></button>
                            <div class="nexa-note-filter-menu" data-nexa-task-status-menu hidden>
                                ${this.filterCheckboxMenu('task-status', this.taskStatusFilterOptions())}
                            </div>
                        </div>
                        <div class="nexa-note-filter" data-nexa-task-priority-filter>
                            <button type="button" data-nexa-task-priority-toggle aria-haspopup="true" aria-expanded="false"><span data-nexa-task-priority-label>Priority</span><span class="fas fa-caret-down" aria-hidden="true"></span></button>
                            <div class="nexa-note-filter-menu" data-nexa-task-priority-menu hidden>
                                ${this.filterCheckboxMenu('task-priority', this.taskPriorityFilterOptions())}
                            </div>
                        </div>
                    </div>
                </div>
                <section class="nexa-contact-notes" aria-labelledby="nexa-contact-tasks-title">
                    <h4 id="nexa-contact-tasks-title" class="sr-only">Tasks</h4><span class="sr-only" data-nexa-task-count>0 tasks</span>
                    <div class="nexa-contact-note-list" data-nexa-task-list aria-live="polite">
                        <div class="nexa-note-loading"><span class="fas fa-circle-notch fa-spin" aria-hidden="true"></span><span>Loading tasks</span></div>
                    </div>
                </section>
            </section>`;
        }

        meetingsPanel() {
            return `<section class="nexa-customer-tab-panel nexa-notes-workspace" data-nexa-tab-panel="meetings" role="tabpanel" hidden>
                <div class="nexa-notes-toolbar">
                    <div class="nexa-notes-toolbar-primary">
                        <label class="nexa-notes-search"><span class="sr-only">Search meetings</span><input type="search" data-nexa-meetings-search placeholder="Search meetings"><span class="fas fa-search" aria-hidden="true"></span></label>
                        <div class="nexa-notes-toolbar-actions">
                            <button type="button" class="nexa-collapse-notes" data-nexa-collapse-meetings>Collapse all <span class="fas fa-caret-down" aria-hidden="true"></span></button>
                            <button type="button" class="btn btn-default" data-nexa-create-meeting><span class="far fa-calendar" aria-hidden="true"></span><span>Schedule a meeting</span></button>
                        </div>
                    </div>
                    <button type="button" class="btn btn-default nexa-notes-filter-toggle" data-nexa-meetings-filter-toggle aria-expanded="true"><span class="fas fa-sliders-h" aria-hidden="true"></span><span>Filters</span></button>
                    <div class="nexa-notes-filters" data-nexa-meetings-filters>
                        <div class="nexa-note-filter" data-nexa-meeting-period-filter>
                            <button type="button" data-nexa-meeting-period-toggle aria-haspopup="true" aria-expanded="false"><span data-nexa-meeting-period-label>All time</span><span class="fas fa-caret-down" aria-hidden="true"></span></button>
                            <div class="nexa-note-filter-menu nexa-note-period-menu" data-nexa-meeting-period-menu hidden>
                                ${this.notePeriodOptions().map(([value, label]) => `<button type="button" data-nexa-meeting-period="${value}">${label}</button>`).join('')}
                            </div>
                        </div>
                        <div class="nexa-note-filter" data-nexa-meeting-owner-filter>
                            <button type="button" data-nexa-meeting-owner-toggle aria-haspopup="true" aria-expanded="false"><span data-nexa-meeting-owner-label>Activity assigned to</span><span class="fas fa-caret-down" aria-hidden="true"></span></button>
                            <div class="nexa-note-filter-menu nexa-note-owner-menu" data-nexa-meeting-owner-menu hidden>
                                <label><span class="fas fa-search" aria-hidden="true"></span><input type="search" data-nexa-meeting-owner-search placeholder="Search owners" aria-label="Search owners"></label>
                                <div data-nexa-meeting-owner-options></div>
                            </div>
                        </div>
                        <div class="nexa-note-filter" data-nexa-meeting-status-filter>
                            <button type="button" data-nexa-meeting-status-toggle aria-haspopup="true" aria-expanded="false"><span data-nexa-meeting-status-label>Meeting outcome</span><span class="fas fa-caret-down" aria-hidden="true"></span></button>
                            <div class="nexa-note-filter-menu" data-nexa-meeting-status-menu hidden>
                                ${this.filterCheckboxMenu('meeting-status', this.meetingStatusFilterOptions())}
                            </div>
                        </div>
                    </div>
                </div>
                <section class="nexa-contact-notes" aria-labelledby="nexa-contact-meetings-title">
                    <h4 id="nexa-contact-meetings-title" class="sr-only">Meetings</h4><span class="sr-only" data-nexa-meeting-count>0 meetings</span>
                    <div class="nexa-contact-note-list" data-nexa-meeting-list aria-live="polite">
                        <div class="nexa-note-loading"><span class="fas fa-circle-notch fa-spin" aria-hidden="true"></span><span>Loading meetings</span></div>
                    </div>
                </section>
            </section>`;
        }

        callsPanel() {
            return `<section class="nexa-customer-tab-panel nexa-notes-workspace" data-nexa-tab-panel="calls" role="tabpanel" hidden>
                <div class="nexa-notes-toolbar">
                    <div class="nexa-notes-toolbar-primary">
                        <label class="nexa-notes-search"><span class="sr-only">Search calls</span><input type="search" data-nexa-callrecords-search placeholder="Search calls"><span class="fas fa-search" aria-hidden="true"></span></label>
                        <div class="nexa-notes-toolbar-actions">
                            <button type="button" class="nexa-collapse-notes" data-nexa-collapse-callrecords>Collapse all <span class="fas fa-caret-down" aria-hidden="true"></span></button>
                            <button type="button" class="btn btn-default" data-nexa-create-callrecord><span class="fas fa-phone" aria-hidden="true"></span><span>Schedule a call</span></button>
                        </div>
                    </div>
                    <button type="button" class="btn btn-default nexa-notes-filter-toggle" data-nexa-callrecords-filter-toggle aria-expanded="true"><span class="fas fa-sliders-h" aria-hidden="true"></span><span>Filters</span></button>
                    <div class="nexa-notes-filters" data-nexa-callrecords-filters>
                        <div class="nexa-note-filter" data-nexa-callrecord-period-filter>
                            <button type="button" data-nexa-callrecord-period-toggle aria-haspopup="true" aria-expanded="false"><span data-nexa-callrecord-period-label>All time</span><span class="fas fa-caret-down" aria-hidden="true"></span></button>
                            <div class="nexa-note-filter-menu nexa-note-period-menu" data-nexa-callrecord-period-menu hidden>
                                ${this.notePeriodOptions().map(([value, label]) => `<button type="button" data-nexa-callrecord-period="${value}">${label}</button>`).join('')}
                            </div>
                        </div>
                        <div class="nexa-note-filter" data-nexa-callrecord-owner-filter>
                            <button type="button" data-nexa-callrecord-owner-toggle aria-haspopup="true" aria-expanded="false"><span data-nexa-callrecord-owner-label>Activity assigned to</span><span class="fas fa-caret-down" aria-hidden="true"></span></button>
                            <div class="nexa-note-filter-menu nexa-note-owner-menu" data-nexa-callrecord-owner-menu hidden>
                                <label><span class="fas fa-search" aria-hidden="true"></span><input type="search" data-nexa-callrecord-owner-search placeholder="Search owners" aria-label="Search owners"></label>
                                <div data-nexa-callrecord-owner-options></div>
                            </div>
                        </div>
                        <div class="nexa-note-filter" data-nexa-callrecord-status-filter>
                            <button type="button" data-nexa-callrecord-status-toggle aria-haspopup="true" aria-expanded="false"><span data-nexa-callrecord-status-label>Call outcome</span><span class="fas fa-caret-down" aria-hidden="true"></span></button>
                            <div class="nexa-note-filter-menu" data-nexa-callrecord-status-menu hidden>
                                ${this.filterCheckboxMenu('callrecord-status', this.meetingStatusFilterOptions())}
                            </div>
                        </div>
                    </div>
                </div>
                <section class="nexa-contact-notes" aria-labelledby="nexa-contact-calls-title">
                    <h4 id="nexa-contact-calls-title" class="sr-only">Calls</h4><span class="sr-only" data-nexa-callrecord-count>0 calls</span>
                    <div class="nexa-contact-note-list" data-nexa-callrecord-list aria-live="polite">
                        <div class="nexa-note-loading"><span class="fas fa-circle-notch fa-spin" aria-hidden="true"></span><span>Loading calls</span></div>
                    </div>
                </section>
            </section>`;
        }

        emailsPanel() {
            return `<section class="nexa-customer-tab-panel nexa-notes-workspace" data-nexa-tab-panel="email" role="tabpanel" hidden>
                <div class="nexa-notes-toolbar">
                    <div class="nexa-notes-toolbar-primary">
                        <label class="nexa-notes-search"><span class="sr-only">Search emails</span><input type="search" data-nexa-emailrecords-search placeholder="Search emails"><span class="fas fa-search" aria-hidden="true"></span></label>
                        <div class="nexa-notes-toolbar-actions">
                            <button type="button" class="nexa-collapse-notes" data-nexa-collapse-emailrecords>Collapse all <span class="fas fa-caret-down" aria-hidden="true"></span></button>
                            <button type="button" class="btn btn-link btn-sm" data-nexa-manage-inbox><span class="fas fa-plug" aria-hidden="true"></span><span data-nexa-manage-inbox-label>Inbox connection</span></button>
                            <button type="button" class="btn btn-default" data-nexa-create-emailrecord><span class="far fa-envelope" aria-hidden="true"></span><span>Compose email</span></button>
                        </div>
                    </div>
                    <button type="button" class="btn btn-default nexa-notes-filter-toggle" data-nexa-emailrecords-filter-toggle aria-expanded="true"><span class="fas fa-sliders-h" aria-hidden="true"></span><span>Filters</span></button>
                    <div class="nexa-notes-filters" data-nexa-emailrecords-filters>
                        <div class="nexa-note-filter" data-nexa-emailrecord-period-filter>
                            <button type="button" data-nexa-emailrecord-period-toggle aria-haspopup="true" aria-expanded="false"><span data-nexa-emailrecord-period-label>All time</span><span class="fas fa-caret-down" aria-hidden="true"></span></button>
                            <div class="nexa-note-filter-menu nexa-note-period-menu" data-nexa-emailrecord-period-menu hidden>
                                ${this.notePeriodOptions().map(([value, label]) => `<button type="button" data-nexa-emailrecord-period="${value}">${label}</button>`).join('')}
                            </div>
                        </div>
                        <div class="nexa-note-filter" data-nexa-emailrecord-status-filter>
                            <button type="button" data-nexa-emailrecord-status-toggle aria-haspopup="true" aria-expanded="false"><span data-nexa-emailrecord-status-label>Email status</span><span class="fas fa-caret-down" aria-hidden="true"></span></button>
                            <div class="nexa-note-filter-menu" data-nexa-emailrecord-status-menu hidden>
                                ${this.filterCheckboxMenu('emailrecord-status', this.emailStatusFilterOptions())}
                            </div>
                        </div>
                    </div>
                </div>
                <section class="nexa-contact-notes" aria-labelledby="nexa-contact-emails-title">
                    <h4 id="nexa-contact-emails-title" class="sr-only">Email</h4><span class="sr-only" data-nexa-emailrecord-count>0 emails</span>
                    <div class="nexa-contact-note-list" data-nexa-emailrecord-list aria-live="polite">
                        <div class="nexa-note-loading"><span class="fas fa-circle-notch fa-spin" aria-hidden="true"></span><span>Loading emails</span></div>
                    </div>
                </section>
            </section>`;
        }

        emailStatusFilterOptions() {
            return [
                ['Draft', 'Draft'], ['Sending', 'Sending'], ['Sent', 'Sent'],
                ['Archived', 'Archived'], ['Failed', 'Failed'],
            ];
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

        taskStatusFilterOptions() {
            return [
                ['Not Started', 'Not Started'], ['Started', 'In Progress'], ['Waiting', 'Waiting'],
                ['Completed', 'Completed'], ['Canceled', 'Canceled'], ['Deferred', 'Deferred'],
            ];
        }

        taskPriorityFilterOptions() {
            return [
                ['none', 'None'], ['Low', 'Low'], ['Medium', 'Medium'], ['High', 'High'],
            ];
        }

        meetingStatusFilterOptions() {
            return [
                ['Planned', 'Planned'], ['Held', 'Held'], ['Not Held', 'Not Held'],
            ];
        }

        filterCheckboxMenu(name, options) {
            return options.map(([value, label]) => `<label class="nexa-filter-checkbox-option"><input type="checkbox" data-nexa-${name}-option value="${this.escape(value)}"><span>${this.escape(label)}</span></label>`).join('');
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
            shell.querySelector('[data-nexa-compose-email]')?.addEventListener('click', () => {
                this.openContactEmailComposer();
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
            this.bindContactTasksWorkspace(shell);
            this.bindContactMeetingsWorkspace(shell);
            this.bindContactCallsWorkspace(shell);
            this.bindContactEmailsWorkspace(shell);
            this.bindContactActivityWorkspace(shell);
        }

        bindContactActivityWorkspace(shell) {
            this.contactActivityFilter = this.contactActivityFilter || {query: '', type: 'all', period: 'all'};
            this.collapsedContactActivityIds = this.collapsedContactActivityIds || new Set();
            this.knownContactActivityIds = this.knownContactActivityIds || new Set();
            shell.querySelector('[data-nexa-activity-search]')?.addEventListener('input', event => {
                this.contactActivityFilter.query = event.currentTarget.value.trim().toLowerCase();
                this.renderContactActivities(shell);
            });
            shell.querySelector('[data-nexa-activity-type]')?.addEventListener('change', event => {
                this.contactActivityFilter.type = event.currentTarget.value;
                this.renderContactActivities(shell);
            });
            shell.querySelector('[data-nexa-activity-period]')?.addEventListener('change', event => {
                this.contactActivityFilter.period = event.currentTarget.value;
                this.renderContactActivities(shell);
            });
            shell.querySelector('[data-nexa-activity-filter-toggle]')?.addEventListener('click', event => {
                const filters = shell.querySelector('[data-nexa-activity-filters]');
                filters.hidden = !filters.hidden;
                event.currentTarget.setAttribute('aria-expanded', String(!filters.hidden));
            });
            shell.querySelector('[data-nexa-collapse-activities]')?.addEventListener('click', () => {
                const activities = this.filteredContactActivities(shell);
                const allCollapsed = activities.length > 0 && activities.every(activity => {
                    if (activity.type === 'task' && activity.taskRef) return this.collapsedContactTaskIds?.has(activity.taskRef.id);
                    if (activity.type === 'meeting' && activity.meetingRef) return this.collapsedContactMeetingIds?.has(activity.meetingRef.id);
                    if (activity.type === 'email' && activity.emailRef) return this.collapsedContactEmailIds?.has(activity.emailRef.id);
                    return this.collapsedContactActivityIds.has(activity.id);
                });
                activities.forEach(activity => {
                    if (activity.type === 'task' && activity.taskRef) {
                        if (allCollapsed) this.collapsedContactTaskIds?.delete(activity.taskRef.id);
                        else this.collapsedContactTaskIds?.add(activity.taskRef.id);
                    } else if (activity.type === 'meeting' && activity.meetingRef) {
                        if (allCollapsed) this.collapsedContactMeetingIds?.delete(activity.meetingRef.id);
                        else this.collapsedContactMeetingIds?.add(activity.meetingRef.id);
                    } else if (activity.type === 'email' && activity.emailRef) {
                        if (allCollapsed) this.collapsedContactEmailIds?.delete(activity.emailRef.id);
                        else this.collapsedContactEmailIds?.add(activity.emailRef.id);
                    } else if (allCollapsed) {
                        this.collapsedContactActivityIds.delete(activity.id);
                    } else {
                        this.collapsedContactActivityIds.add(activity.id);
                    }
                });
                this.renderContactActivities(shell);
            });
            this.renderContactActivities(shell);
        }

        observeContactActivity(container) {
            if (!container) return;
            this.contactActivityObserver?.disconnect();
            this.contactActivityObserver = new MutationObserver(() => {
                window.clearTimeout(this.contactActivityFilterTimer);
                this.contactActivityFilterTimer = window.setTimeout(() => this.renderContactActivities(), 25);
            });
            this.contactActivityObserver.observe(container, {childList: true, subtree: true});
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
            const tooltips = {
                note: 'Create a note',
                email: 'Create email',
                call: 'Make a phone call',
                task: 'Create a task',
                meeting: 'Schedule a meeting',
            };
            const tooltip = tooltips[type] || label;
            return `<button type="button" class="btn btn-link" data-nexa-contact-action="${type}" data-tooltip="${this.escape(tooltip)}"><span class="${icon}" aria-hidden="true"></span><span>${label}</span></button>`;
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
            const top = rect.bottom + 7;

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

        durationOptions() {
            const options = [];
            for (let minutes = 15; minutes <= 480; minutes += 15) {
                options.push([minutes, this.formatDurationMinutes(minutes)]);
            }
            return options;
        }

        formatDurationMinutes(totalMinutes) {
            const minutes = Number(totalMinutes) || 0;
            if (!minutes) return '';
            const hours = Math.floor(minutes / 60);
            const remainder = minutes % 60;
            const parts = [];
            if (hours) parts.push(`${hours} Hour${hours > 1 ? 's' : ''}`);
            if (remainder) parts.push(`${remainder} Minutes`);
            return parts.join(' ');
        }

        openInteractionDialog(channelKey, returnFocus = null) {
            this.closeInteractionDialog();
            const channel = this.interactionChannels()[channelKey] || 'Interaction';
            const isMeeting = channelKey === 'meeting-log';
            const overlay = document.createElement('div');
            const now = new Date();
            const localDate = new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
            overlay.className = 'nexa-interaction-overlay';
            overlay.dataset.nexaInteractionDialog = 'true';
            const topFieldsHtml = isMeeting
                ? `<div class="nexa-interaction-grid">
                    <label><span>Attendees</span><div class="nexa-interaction-attendee"><span class="nexa-interaction-attendee-badge">${this.escape(this.model.get('name') || 'Unknown contact')}</span></div><input type="hidden" name="attendees" value="${this.escape(this.model.get('name') || '')}"></label>
                    <label><span>Meeting start time</span><input class="form-control" type="datetime-local" name="occurredAt" value="${localDate}" required></label>
                </div>
                <label><span>Duration</span><select class="form-control" name="duration">
                    <option value="">Not specified</option>
                    ${this.durationOptions().map(([minutes, label]) => `<option value="${minutes}">${this.escape(label)}</option>`).join('')}
                </select></label>`
                : `<div class="nexa-interaction-grid">
                    <label><span>Direction</span><select class="form-control" name="direction">
                        <option value="Outbound">Outbound</option><option value="Inbound">Inbound</option>
                    </select></label>
                    <label><span>Date and time</span><input class="form-control" type="datetime-local" name="occurredAt" value="${localDate}" required></label>
                </div>`;
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
                        ${topFieldsHtml}
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
                this.saveInteraction(channelKey, channel, new FormData(event.currentTarget));
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

        async saveInteraction(channelKey, channel, formData) {
            const dialog = this.interactionDialog;
            if (!dialog || this.interactionSavePending) return;

            const isMeeting = channelKey === 'meeting-log';
            const subject = String(formData.get('subject') || '').trim();
            const occurredAt = String(formData.get('occurredAt') || '').trim();
            const error = dialog.querySelector('[data-nexa-interaction-error]');
            if (!subject || !occurredAt) {
                error.textContent = isMeeting
                    ? 'Enter a subject and the meeting start time.'
                    : 'Enter a subject and the interaction date and time.';
                error.hidden = false;
                return;
            }

            const outcome = String(formData.get('outcome') || '').trim();
            const notes = String(formData.get('notes') || '').trim();
            const lines = isMeeting
                ? [
                    `[${channel}] ${subject}`,
                    (() => {
                        const attendees = String(formData.get('attendees') || '').trim();
                        return attendees ? `Attendees: ${attendees}` : '';
                    })(),
                    `Start: ${occurredAt.replace('T', ' ')}`,
                    (() => {
                        const durationLabel = this.formatDurationMinutes(formData.get('duration'));
                        return durationLabel ? `Duration: ${durationLabel}` : '';
                    })(),
                    outcome ? `Outcome: ${outcome}` : '',
                    notes ? `\n${notes}` : '',
                ].filter(Boolean)
                : [
                    `[${channel} - ${String(formData.get('direction') || 'Outbound')}] ${subject}`,
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

        highlight(label, value, icon, state = null) {
            const stateClass = state ? ` is-${state}` : '';
            return `<article class="nexa-highlight${stateClass}"><span class="${icon}" aria-hidden="true"></span><div><p>${this.escape(label)}</p><strong>${this.escape(this.displayValue(value))}</strong></div></article>`;
        }

        openActivity(type) {
            if (type === 'email') return this.openContactEmailComposer();
            if (type === 'note') {
                return this.openNoteDialog();
            }
            if (type === 'task') {
                return this.openTaskDialog();
            }
            if (type === 'meeting') {
                return this.openMeetingModal();
            }
            if (type === 'call') {
                return this.openCallPicker();
            }
            this.element.querySelector('[data-nexa-tab="activity"]')?.click();
        }

        async openCallPicker() {
            const callerIdStatus = await this.getCallerIdStatus();
            if (callerIdStatus.status !== 'verified') {
                return this.openCallerIdBlockedState();
            }

            this.closeCallPickerDialog();
            const overlay = document.createElement('div');
            overlay.className = 'nexa-interaction-overlay nexa-call-picker-overlay';
            const contactName = this.model.get('name') || 'Contact';
            overlay.innerHTML = `
                <section class="nexa-interaction-dialog nexa-call-picker-dialog" role="dialog" aria-modal="true" aria-labelledby="nexa-call-picker-title">
                    <header>
                        <div><p>Phone call</p><h2 id="nexa-call-picker-title">${this.escape(contactName)}</h2></div>
                        <button type="button" class="nexa-dialog-close" data-nexa-call-picker-close aria-label="Close">
                            <span class="fas fa-times" aria-hidden="true"></span>
                        </button>
                    </header>
                    <div class="nexa-call-picker-options">
                        <button type="button" class="nexa-call-picker-option" data-nexa-call-picker-make>
                            <span class="nexa-call-picker-icon"><span class="fas fa-phone" aria-hidden="true"></span></span>
                            <span class="nexa-call-picker-text">
                                <strong>Make a call</strong>
                                <span data-nexa-call-minutes>Checking minutes remaining&hellip;</span>
                            </span>
                        </button>
                        <button type="button" class="nexa-call-picker-option" data-nexa-call-picker-schedule>
                            <span class="nexa-call-picker-icon"><span class="far fa-calendar" aria-hidden="true"></span></span>
                            <span class="nexa-call-picker-text">
                                <strong>Schedule a call</strong>
                                <span>Plan a call for later and add it to the calendar</span>
                            </span>
                        </button>
                    </div>
                </section>`;

            document.body.append(overlay);
            this.callPickerDialog = overlay;
            overlay.querySelector('[data-nexa-call-picker-close]').addEventListener('click', () => this.closeCallPickerDialog());
            overlay.addEventListener('mousedown', event => {
                if (event.target === overlay) this.closeCallPickerDialog();
            });
            overlay.addEventListener('keydown', event => {
                if (event.key === 'Escape') this.closeCallPickerDialog();
            });
            overlay.querySelector('[data-nexa-call-picker-make]').addEventListener('click', () => {
                this.closeCallPickerDialog();
                this.openClickToCall();
            });
            overlay.querySelector('[data-nexa-call-picker-schedule]').addEventListener('click', () => {
                this.closeCallPickerDialog();
                this.openScheduleCallModal();
            });

            this.loadCallMinutesSummary(overlay);
        }

        closeCallPickerDialog() {
            this.callPickerDialog?.remove();
            this.callPickerDialog = null;
        }

        async getCallerIdStatus() {
            try {
                return await Espo.Ajax.getRequest('Nexa/call/caller-id');
            } catch (error) {
                return {status: 'unverified', callerNumber: null};
            }
        }

        // Shown instead of the normal call picker whenever the tenant has no
        // verified caller ID yet - admins get the verification popup right
        // here rather than a dead end; everyone else gets told who to ask.
        openCallerIdBlockedState() {
            this.closeCallPickerDialog();

            if (this.getUser().isAdmin()) {
                const modal = new CallerIdVerifyModal({onVerified: () => {}});
                modal.open();
                return;
            }

            const overlay = document.createElement('div');
            overlay.className = 'nexa-interaction-overlay nexa-call-picker-overlay';
            overlay.innerHTML = `
                <section class="nexa-interaction-dialog nexa-call-picker-dialog" role="dialog" aria-modal="true" aria-labelledby="nexa-call-blocked-title">
                    <header>
                        <div><p>Phone call</p><h2 id="nexa-call-blocked-title">Calling isn't set up yet</h2></div>
                        <button type="button" class="nexa-dialog-close" data-nexa-call-picker-close aria-label="Close">
                            <span class="fas fa-times" aria-hidden="true"></span>
                        </button>
                    </header>
                    <div class="nexa-call-picker-options">
                        <p>Ask your tenant admin to verify a business phone number before calls can be made.</p>
                    </div>
                </section>`;

            document.body.append(overlay);
            this.callPickerDialog = overlay;
            overlay.querySelector('[data-nexa-call-picker-close]').addEventListener('click', () => this.closeCallPickerDialog());
            overlay.addEventListener('mousedown', event => {
                if (event.target === overlay) this.closeCallPickerDialog();
            });
            overlay.addEventListener('keydown', event => {
                if (event.key === 'Escape') this.closeCallPickerDialog();
            });
        }

        async loadCallMinutesSummary(overlay) {
            const label = overlay.querySelector('[data-nexa-call-minutes]');
            if (!label) return;
            try {
                const payload = await Espo.Ajax.getRequest('Nexa/call/minutes');
                if (!overlay.isConnected) return;

                const remaining = Math.max(0, Math.floor(payload.remaining));
                const userRemaining = Math.max(0, Math.floor(payload.userRemaining));

                // Two independent ceilings - either one being exhausted blocks
                // calling, so "Request more" has to trigger on whichever one
                // the user actually hit, not just their own personal share.
                if (remaining <= 0) {
                    label.textContent = "This month's plan calling minutes are used up";
                    this.showRequestMinutesOption(overlay);
                    return;
                }
                if (userRemaining <= 0) {
                    label.textContent = `You've used your ${payload.userLimit}-minute monthly share`;
                    this.showRequestMinutesOption(overlay);
                    return;
                }

                label.textContent = `${userRemaining} of ${payload.userLimit} minutes left this month`;
                label.classList.toggle('is-low', payload.userLimit > 0 && userRemaining / payload.userLimit <= 0.2);
            } catch (error) {
                if (overlay.isConnected) label.textContent = 'Minutes remaining unavailable right now';
            }
        }

        // Swaps the (now unusable) "Make a call" option for a "Request more
        // minutes" option, since the shared pool is exhausted - the request
        // form itself replaces the picker's option list in place.
        showRequestMinutesOption(overlay) {
            const makeOption = overlay.querySelector('[data-nexa-call-picker-make]');
            if (!makeOption) return;

            makeOption.outerHTML = `
                <button type="button" class="nexa-call-picker-option" data-nexa-call-picker-request>
                    <span class="nexa-call-picker-icon"><span class="fas fa-hand-paper" aria-hidden="true"></span></span>
                    <span class="nexa-call-picker-text">
                        <strong>Request more minutes</strong>
                        <span>This month's calling minutes are used up - ask your admin for more</span>
                    </span>
                </button>`;

            overlay.querySelector('[data-nexa-call-picker-request]')?.addEventListener('click', () => {
                this.showCreditRequestForm(overlay);
            });
        }

        showCreditRequestForm(overlay) {
            const dialog = overlay.querySelector('.nexa-call-picker-dialog');
            if (!dialog) return;

            dialog.innerHTML = `
                <header>
                    <div><p>Phone call</p><h2>Request more calling minutes</h2></div>
                    <button type="button" class="nexa-dialog-close" data-nexa-call-picker-close aria-label="Close">
                        <span class="fas fa-times" aria-hidden="true"></span>
                    </button>
                </header>
                <form class="nexa-credit-request-form" data-nexa-credit-request-form>
                    <label><span>Minutes needed</span><input class="form-control" type="number" name="minutes" min="1" max="500" value="30" required></label>
                    <label><span>Why do you need more minutes?</span><textarea class="form-control" name="reason" rows="3" maxlength="1000" placeholder="e.g. Following up on a large deal that needs a call today" required></textarea></label>
                    <p class="nexa-interaction-error" data-nexa-credit-request-error role="alert" hidden></p>
                    <footer>
                        <button type="button" class="btn btn-default" data-nexa-dialog-close>Cancel</button>
                        <button type="submit" class="btn btn-primary" data-nexa-credit-request-submit>Send request</button>
                    </footer>
                </form>`;

            dialog.querySelector('[data-nexa-call-picker-close]').addEventListener('click', () => this.closeCallPickerDialog());
            dialog.querySelector('[data-nexa-dialog-close]').addEventListener('click', () => this.closeCallPickerDialog());
            dialog.querySelector('[data-nexa-credit-request-form]').addEventListener('submit', event => {
                event.preventDefault();
                this.submitCreditRequest(dialog, new FormData(event.currentTarget));
            });
        }

        async submitCreditRequest(dialog, formData) {
            const error = dialog.querySelector('[data-nexa-credit-request-error]');
            const submit = dialog.querySelector('[data-nexa-credit-request-submit]');
            const requestedMinutes = parseInt(formData.get('minutes'), 10);
            const reason = String(formData.get('reason') || '').trim();

            if (!requestedMinutes || requestedMinutes < 1 || !reason) {
                error.textContent = 'Enter how many minutes you need and why.';
                error.hidden = false;
                return;
            }

            error.hidden = true;
            submit.disabled = true;
            submit.classList.add('is-loading');

            try {
                await Espo.Ajax.postRequest('Nexa/call/credit-request', {requestedMinutes, reason});
                dialog.innerHTML = `
                    <header><div><p>Phone call</p><h2>Request sent</h2></div>
                        <button type="button" class="nexa-dialog-close" data-nexa-call-picker-close aria-label="Close"><span class="fas fa-times" aria-hidden="true"></span></button>
                    </header>
                    <div class="nexa-call-picker-options">
                        <p class="nexa-credit-request-sent">Your request was sent to your tenant admin. You'll be notified once it's reviewed.</p>
                        <button type="button" class="btn btn-default" data-nexa-dialog-close>Close</button>
                    </div>`;
                dialog.querySelectorAll('[data-nexa-call-picker-close], [data-nexa-dialog-close]').forEach(button => {
                    button.addEventListener('click', () => this.closeCallPickerDialog());
                });
            } catch (saveError) {
                error.textContent = 'The request could not be sent. Check your access and try again.';
                error.hidden = false;
                submit.disabled = false;
                submit.classList.remove('is-loading');
            }
        }

        normalizePhoneForCall(rawNumber) {
            const stripped = String(rawNumber || '').replace(/[^\d+]/g, '');
            return /^\+[1-9]\d{6,14}$/.test(stripped) ? stripped : null;
        }

        formatCallTimer(totalSeconds) {
            const minutes = Math.floor(totalSeconds / 60);
            const seconds = totalSeconds % 60;
            return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
        }

        async openClickToCall() {
            const toNumber = this.normalizePhoneForCall(this.model.get('phoneNumber'));
            if (!toNumber) {
                Espo.Ui.error('Add a valid phone number (with country code) to this contact before calling.');
                return;
            }

            this.closeCallDialog();
            this.renderCallDialog(this.model.get('name') || 'Contact', toNumber);
            this.setCallStatus('Requesting microphone access…');

            try {
                await this.ensureMicrophoneAccess();
                this.setCallStatus('Connecting…');
                const tokenPayload = await Espo.Ajax.postRequest('Nexa/call/token', {});
                const initiatePayload = await Espo.Ajax.postRequest('Nexa/call/initiate', {
                    contactId: this.model.id,
                    toNumber,
                });

                if (!this.callController) {
                    this.callController = new TwilioCallController(this.getBasePath());
                }

                this.setCallStatus('Ringing…');
                await this.callController.startCall(tokenPayload.token, initiatePayload.correlationId, toNumber, {
                    onAccept: () => {
                        this.callConnectedAt = Date.now();
                        this.setCallStatus(`Connected • ${this.formatCallTimer(0)}`);
                        this.callTimerInterval = window.setInterval(() => {
                            const elapsed = Math.floor((Date.now() - this.callConnectedAt) / 1000);
                            this.setCallStatus(`Connected • ${this.formatCallTimer(elapsed)}`);
                        }, 1000);
                    },
                    onDisconnect: () => this.endCallDialog('Call ended'),
                    onCancel: () => this.endCallDialog('Call canceled'),
                    onReject: () => this.endCallDialog('Call declined'),
                    onError: error => this.endCallDialog(error?.message || 'Call error', true),
                    onDeviceError: error => this.endCallDialog(error?.message || 'Call error', true),
                });
            } catch (error) {
                this.endCallDialog(this.describeCallStartError(error), true);
            }
        }

        // Requests mic access as its own explicit, predictable step - rather
        // than leaving it to happen implicitly deep inside the Twilio SDK's
        // own connect() call, where a denial surfaces as an opaque, generic
        // failure with no indication the problem is permissions at all.
        // Twilio's SDK requests its own stream once connected; this call
        // exists purely to trigger the browser prompt and catch a clear
        // rejection - the stream itself is stopped immediately after.
        async ensureMicrophoneAccess() {
            if (!navigator.mediaDevices?.getUserMedia) {
                throw Object.assign(new Error('MIC_UNSUPPORTED'), {code: 'MIC_UNSUPPORTED'});
            }

            try {
                const stream = await navigator.mediaDevices.getUserMedia({audio: true});
                stream.getTracks().forEach(track => track.stop());
            } catch (error) {
                throw Object.assign(new Error(error?.name || 'MIC_ERROR'), {code: error?.name || 'MIC_ERROR'});
            }
        }

        describeCallStartError(error) {
            if (error?.code === 'NotAllowedError' || error?.code === 'PermissionDeniedError') {
                return 'Microphone access is required to make calls. Allow microphone access for this site in your browser settings, then try again.';
            }
            if (error?.code === 'NotFoundError' || error?.code === 'DevicesNotFoundError') {
                return 'No microphone was found. Connect a microphone and try again.';
            }
            if (error?.code === 'MIC_UNSUPPORTED') {
                return 'This browser or connection does not support microphone access. Calling requires a secure (HTTPS) connection.';
            }

            const reason = error?.getResponseHeader?.('X-Status-Reason') || '';
            if (reason === 'MINUTES_EXHAUSTED') {
                return "This month's plan calling minutes are used up. Close this and request more from the call menu.";
            }
            if (reason === 'USER_SHARE_EXHAUSTED') {
                return "You've used your own monthly share of calling minutes. Close this and request more from the call menu.";
            }
            if (reason === 'CALLER_ID_NOT_VERIFIED') {
                return 'No verified business phone number is set up for calling yet. Ask your tenant admin to verify one.';
            }
            if (error?.status === 403) {
                return 'Calling is not available on your current plan.';
            }

            return 'Could not start the call. Check the number and try again.';
        }

        renderCallDialog(contactName, toNumber) {
            const overlay = document.createElement('div');
            overlay.className = 'nexa-interaction-overlay nexa-call-overlay';
            overlay.innerHTML = `
                <section class="nexa-interaction-dialog nexa-call-dialog" role="dialog" aria-modal="true" aria-labelledby="nexa-call-title">
                    <header>
                        <div><p>Phone call</p><h2 id="nexa-call-title">${this.escape(contactName)}</h2></div>
                        <button type="button" class="nexa-dialog-close" data-nexa-call-close aria-label="Close">
                            <span class="fas fa-times" aria-hidden="true"></span>
                        </button>
                    </header>
                    <div class="nexa-call-panel">
                        <div class="nexa-call-avatar"><span class="fas fa-phone" aria-hidden="true"></span></div>
                        <p class="nexa-call-number">${this.escape(toNumber)}</p>
                        <p class="nexa-call-status" data-nexa-call-status>Connecting…</p>
                        <footer>
                            <button type="button" class="btn btn-danger" data-nexa-call-hangup>
                                <span class="fas fa-phone-slash" aria-hidden="true"></span><span>Hang up</span>
                            </button>
                            <button type="button" class="btn btn-default" data-nexa-call-done hidden>Close</button>
                        </footer>
                    </div>
                </section>`;

            document.body.append(overlay);
            this.callDialog = overlay;
            overlay.querySelector('[data-nexa-call-close]').addEventListener('click', () => this.closeCallDialog());
            overlay.querySelector('[data-nexa-call-done]').addEventListener('click', () => this.closeCallDialog());
            overlay.querySelector('[data-nexa-call-hangup]').addEventListener('click', () => this.callController?.hangUp());
            overlay.addEventListener('mousedown', event => {
                if (event.target === overlay) this.closeCallDialog();
            });
            overlay.addEventListener('keydown', event => {
                if (event.key === 'Escape') this.closeCallDialog();
            });
        }

        setCallStatus(text) {
            const status = this.callDialog?.querySelector('[data-nexa-call-status]');
            if (status) status.textContent = text;
        }

        endCallDialog(message, isError = false) {
            if (this.callTimerInterval) {
                window.clearInterval(this.callTimerInterval);
                this.callTimerInterval = null;
            }
            this.setCallStatus(message);
            const status = this.callDialog?.querySelector('[data-nexa-call-status]');
            status?.classList.toggle('is-error', isError);
            this.callDialog?.querySelector('[data-nexa-call-hangup]')?.setAttribute('hidden', 'hidden');
            this.callDialog?.querySelector('[data-nexa-call-done]')?.removeAttribute('hidden');
        }

        closeCallDialog() {
            if (this.callTimerInterval) {
                window.clearInterval(this.callTimerInterval);
                this.callTimerInterval = null;
            }
            this.callController?.hangUp();
            this.callDialog?.remove();
            this.callDialog = null;
        }

        async openContactEmailComposer({subject = ''} = {}) {
            if (!this.getAcl().checkScope('Email', 'create')) {
                Espo.Ui.error('You do not have permission to send email.');
                return;
            }
            if (!(await this.hasConnectedMailbox())) {
                return this.openConnectInboxPrompt();
            }

            const emailAddress = String(this.model.get('emailAddress') || '').trim();
            const contactName = this.model.get('name') || emailAddress;
            const blockedChannels = String(this.model.get('doNotContactChannels') || '')
                .split(',').map(channel => channel.trim()).filter(Boolean);

            if (!this.getAcl().checkField('Contact', 'emailAddress', 'read') || !emailAddress) {
                Espo.Ui.error('Add an email address to this contact before composing a message.');
                return;
            }
            if (blockedChannels.includes('email') || this.model.get('emailAddressIsOptedOut')) {
                Espo.Ui.error('Email is restricted for this contact. Remove the communication restriction before sending.');
                return;
            }

            const attributes = {
                status: 'Draft',
                to: emailAddress,
                nameHash: {[emailAddress]: contactName},
            };
            if (this.connectedMailboxAddress) {
                attributes.from = this.connectedMailboxAddress;
            }
            if (subject) {
                attributes.name = subject;
            }
            if (this.getConfig().get('b2cMode') || !this.model.get('accountId')) {
                attributes.parentType = 'Contact';
                attributes.parentId = this.model.id;
                attributes.parentName = contactName;
            } else {
                attributes.parentType = 'Account';
                attributes.parentId = this.model.get('accountId');
                attributes.parentName = this.model.get('accountName');
                attributes.accountId = this.model.get('accountId');
                attributes.accountName = this.model.get('accountName');
            }

            let relate = null;
            const emailLink = this.model.defs?.links?.emails;
            if (emailLink?.foreign) relate = {model: this.model, link: emailLink.foreign};

            Espo.Ui.notifyWait();
            const viewName = this.getMetadata().get('clientDefs.Email.modalViews.compose') || 'views/modals/compose-email';
            this.createView('nexaComposeEmail', viewName, {
                relate,
                attributes,
                focusForCreate: true,
            }, view => {
                view.render();
                view.notify(false);
                this.listenToOnce(view, 'after:save', () => {
                    this.loadContactEmails();
                    this.model.trigger('update-related:emails');
                    this.model.trigger('update-related:activities');
                    this.model.trigger('after:relate');
                });
            });
        }

        replyToContactEmail(email) {
            const subjectLine = email.subject || email.name || 'your message';
            const prefixed = /^re:/i.test(subjectLine) ? subjectLine : `Re: ${subjectLine}`;
            return this.openContactEmailComposer({subject: prefixed});
        }

        async hasConnectedMailbox() {
            if (this.mailboxConnected) return true;

            // Must match the user's CURRENT profile email specifically, not
            // just "any connected mailbox exists" - found the hard way: if a
            // user changes their profile email after connecting a mailbox
            // for the old one, compose silently defaults From to the new
            // (unconnected) address, which has no matching EmailAccount and
            // falls through to the shared system SMTP with no visible error.
            const profileEmail = String(this.getUser().get('emailAddress') || '').trim();
            if (!profileEmail) {
                this.mailboxConnected = false;
                this.connectedMailboxAddress = null;
                return false;
            }

            try {
                const payload = await Espo.Ajax.getRequest('EmailAccount', {
                    maxSize: 1,
                    select: 'id,emailAddress',
                    where: [
                        {type: 'isTrue', attribute: 'useSmtp'},
                        {type: 'equals', attribute: 'status', value: 'Active'},
                        {type: 'equals', attribute: 'emailAddress', value: profileEmail},
                    ],
                });
                const record = (payload && payload.list || [])[0];
                this.mailboxConnected = !!record;
                this.connectedMailboxAddress = record ? record.emailAddress : null;
            } catch (error) {
                this.mailboxConnected = false;
                this.connectedMailboxAddress = null;
            }
            return this.mailboxConnected;
        }

        // openConnectInboxModal() is normally gated behind hasConnectedMailbox()
        // (only shown when nothing is connected yet), so there was no way for
        // a user to voluntarily re-run it - e.g. to pick up a broader OAuth
        // scope granted after the first connection. This toolbar link always
        // opens it directly, connected or not; the OAuth finish step itself
        // updates the existing mailbox connection in place rather than
        // duplicating it.
        async refreshManageInboxLabel(shell) {
            const label = shell.querySelector('[data-nexa-manage-inbox-label]');
            if (!label) return;
            const connected = await this.hasConnectedMailbox();
            label.textContent = connected
                ? `Connected as ${this.connectedMailboxAddress} · Reconnect`
                : 'Connect inbox';
        }

        closeConnectInboxDialog() {
            this.connectInboxDialog?.remove();
            this.connectInboxDialog = null;
        }

        // Gate shown instead of compose when the current user has no personal
        // mailbox connected yet - unlike caller-id verification, every user
        // (not just admins) self-serves this by connecting their own inbox.
        openConnectInboxPrompt() {
            this.closeConnectInboxDialog();

            const overlay = document.createElement('div');
            overlay.className = 'nexa-interaction-overlay nexa-connect-inbox-overlay';
            overlay.innerHTML = `
                <section class="nexa-interaction-dialog nexa-connect-inbox-dialog" role="dialog" aria-modal="true" aria-labelledby="nexa-connect-inbox-title">
                    <header>
                        <div><p>Email</p><h2 id="nexa-connect-inbox-title">Keep track of your email activity in your CRM</h2></div>
                        <button type="button" class="nexa-dialog-close" data-nexa-connect-inbox-close aria-label="Close">
                            <span class="fas fa-times" aria-hidden="true"></span>
                        </button>
                    </header>
                    <div class="nexa-connect-inbox-intro">
                        <p>Connect your email account to begin sending emails from your CRM. Messages you send will show your own address, not a shared one.</p>
                        <ul class="nexa-connect-inbox-benefits">
                            <li><span class="fas fa-paper-plane" aria-hidden="true"></span>Send emails from your own address</li>
                            <li><span class="fas fa-user-check" aria-hidden="true"></span>Recipients reply straight to you</li>
                            <li><span class="fas fa-lock" aria-hidden="true"></span>Your password is stored securely and only used to send</li>
                        </ul>
                        <button type="button" class="btn btn-primary btn-block" data-nexa-connect-inbox-start>Connect Inbox</button>
                    </div>
                </section>`;

            document.body.append(overlay);
            this.connectInboxDialog = overlay;
            overlay.querySelector('[data-nexa-connect-inbox-close]').addEventListener('click', () => this.closeConnectInboxDialog());
            overlay.addEventListener('mousedown', event => {
                if (event.target === overlay) this.closeConnectInboxDialog();
            });
            overlay.addEventListener('keydown', event => {
                if (event.key === 'Escape') this.closeConnectInboxDialog();
            });
            overlay.querySelector('[data-nexa-connect-inbox-start]').addEventListener('click', () => this.openConnectInboxModal());
        }

        wireConnectInboxDialogChrome(overlay) {
            overlay.querySelectorAll('[data-nexa-connect-inbox-close]').forEach(button => {
                button.addEventListener('click', () => this.closeConnectInboxDialog());
            });
            overlay.addEventListener('mousedown', event => {
                if (event.target === overlay) this.closeConnectInboxDialog();
            });
            overlay.addEventListener('keydown', event => {
                if (event.key === 'Escape') this.closeConnectInboxDialog();
            });
        }

        async getMailOAuthProviders() {
            if (this.mailOAuthProviders) return this.mailOAuthProviders;
            try {
                const payload = await Espo.Ajax.getRequest('Nexa/mail/oauth/providers');
                this.mailOAuthProviders = (payload?.list || []).map(item => item.key);
            } catch (error) {
                this.mailOAuthProviders = [];
            }
            return this.mailOAuthProviders;
        }

        detectMailProvider(email) {
            const domain = String(email).split('@')[1]?.toLowerCase() || '';
            if (['gmail.com', 'googlemail.com'].includes(domain)) return 'google';
            if (['outlook.com', 'hotmail.com', 'live.com', 'msn.com', 'office365.com'].includes(domain)) return 'microsoft';
            return null;
        }

        // Always connects the user's CURRENT profile email specifically -
        // no free-text entry here. Found the hard way why this matters: if a
        // user could connect an address different from their profile email,
        // hasConnectedMailbox()'s required match (see its docblock) would
        // never recognize that connection as valid, forever re-prompting
        // despite a real, working connection existing. Tying them together
        // makes "connected address" and "profile email" the same question,
        // so the two can never drift apart. Recognized Gmail/Microsoft
        // domains branch to one-click OAuth (if configured); everything
        // else falls through to the manual IMAP/SMTP form.
        async openConnectInboxModal() {
            this.closeConnectInboxDialog();

            const email = String(this.getUser().get('emailAddress') || '').trim();
            if (!email) {
                Espo.Ui.error('Add an email address to your profile before connecting an inbox.');
                return;
            }

            const provider = this.detectMailProvider(email);

            if (!provider) {
                // Not a recognized Gmail/Microsoft domain - only case
                // where a password-based IMAP/SMTP form makes sense.
                return this.openConnectInboxManualForm(email);
            }

            const configured = await this.getMailOAuthProviders();
            if (configured.includes(provider)) {
                this.openConnectInboxOAuthChoice(email, provider);
            } else {
                // Recognized Gmail/Microsoft address, but one-click isn't
                // set up yet - never fall through to a password form here.
                // Google/Microsoft mostly reject plain-password IMAP/SMTP
                // now anyway, so that would just fail confusingly.
                this.openConnectInboxProviderUnavailable(email, provider);
            }
        }

        openConnectInboxProviderUnavailable(email, provider) {
            this.closeConnectInboxDialog();

            const label = provider === 'google' ? 'Google' : 'Microsoft';
            const overlay = document.createElement('div');
            overlay.className = 'nexa-interaction-overlay nexa-connect-inbox-overlay';
            overlay.innerHTML = `
                <section class="nexa-interaction-dialog nexa-connect-inbox-dialog" role="dialog" aria-modal="true" aria-labelledby="nexa-connect-inbox-unavailable-title">
                    <header>
                        <div><p>Email</p><h2 id="nexa-connect-inbox-unavailable-title">${this.escape(email)}</h2></div>
                        <button type="button" class="nexa-dialog-close" data-nexa-connect-inbox-close aria-label="Close">
                            <span class="fas fa-times" aria-hidden="true"></span>
                        </button>
                    </header>
                    <div class="nexa-connect-inbox-intro">
                        <p>${this.escape(label)} sign-in isn't set up for this workspace yet. Ask your admin to enable it, or use a different email address.</p>
                        <button type="button" class="btn btn-default btn-block" data-nexa-connect-inbox-close>Close</button>
                    </div>
                </section>`;

            document.body.append(overlay);
            this.connectInboxDialog = overlay;
            this.wireConnectInboxDialogChrome(overlay);
        }

        openConnectInboxOAuthChoice(email, provider) {
            this.closeConnectInboxDialog();

            const label = provider === 'google' ? 'Google' : 'Microsoft';
            const overlay = document.createElement('div');
            overlay.className = 'nexa-interaction-overlay nexa-connect-inbox-overlay';
            overlay.innerHTML = `
                <section class="nexa-interaction-dialog nexa-connect-inbox-dialog" role="dialog" aria-modal="true" aria-labelledby="nexa-connect-inbox-oauth-title">
                    <header>
                        <div><p>Email</p><h2 id="nexa-connect-inbox-oauth-title">${this.escape(email)}</h2></div>
                        <button type="button" class="nexa-dialog-close" data-nexa-connect-inbox-close aria-label="Close">
                            <span class="fas fa-times" aria-hidden="true"></span>
                        </button>
                    </header>
                    <div class="nexa-connect-inbox-intro">
                        <div data-nexa-connect-inbox-error></div>
                        <p>This looks like a ${this.escape(label)} address. Connect it with one click — no password needed.</p>
                        <button type="button" class="btn btn-primary btn-block" data-nexa-connect-inbox-oauth-continue>
                            Continue with ${this.escape(label)}
                        </button>
                        <button type="button" class="btn btn-link btn-block" data-nexa-connect-inbox-manual-fallback>
                            Use a different sign-in method
                        </button>
                    </div>
                </section>`;

            document.body.append(overlay);
            this.connectInboxDialog = overlay;
            this.wireConnectInboxDialogChrome(overlay);
            overlay.querySelector('[data-nexa-connect-inbox-oauth-continue]')
                .addEventListener('click', () => this.startMailOAuthConnect(provider, overlay));
            overlay.querySelector('[data-nexa-connect-inbox-manual-fallback]')
                .addEventListener('click', () => this.openConnectInboxManualForm(email));
        }

        async startMailOAuthConnect(provider, overlay) {
            const errorBox = overlay.querySelector('[data-nexa-connect-inbox-error]');
            const suppressGlobalError = {error: xhr => { xhr.errorIsHandled = true; }};
            errorBox.innerHTML = '';

            let authorizationUrl;
            try {
                const result = await Espo.Ajax.postRequest(`Nexa/mail/oauth/${provider}/start`, {}, suppressGlobalError);
                authorizationUrl = result.authorizationUrl;
            } catch (error) {
                errorBox.innerHTML = `<p class="nexa-interaction-error">Couldn't start the connection. Please try again.</p>`;
                return;
            }

            const popup = window.open(authorizationUrl, 'nexa-mail-oauth', 'width=520,height=640');
            if (!popup) {
                errorBox.innerHTML = `<p class="nexa-interaction-error">Your browser blocked the popup. Please allow popups for this site and try again.</p>`;
                return;
            }

            const handleMessage = async event => {
                if (event.origin !== window.location.origin) return;
                if (!event.data || event.data.source !== 'nexa-mail-oauth') return;
                window.removeEventListener('message', handleMessage);

                if (!event.data.state) {
                    errorBox.innerHTML = `<p class="nexa-interaction-error">Connection was cancelled.</p>`;
                    return;
                }

                try {
                    const finished = await Espo.Ajax.postRequest(`Nexa/mail/oauth/${provider}/finish`, {state: event.data.state}, suppressGlobalError);
                    this.mailboxConnected = true;
                    this.connectedMailboxAddress = finished?.emailAddress || null;
                    Espo.Ui.success('Inbox connected.');
                    this.closeConnectInboxDialog();
                    this.openContactEmailComposer();
                } catch (error) {
                    errorBox.innerHTML = `<p class="nexa-interaction-error">Couldn't finish connecting your inbox. Please try again.</p>`;
                }
            };
            window.addEventListener('message', handleMessage);
        }

        // The manual IMAP/SMTP form - used for any address that isn't a
        // recognized/configured OAuth provider, or when the user opts out
        // of one-click connect. Validates via the core EmailAccount "test
        // connection" action before saving.
        openConnectInboxManualForm(prefillEmail = '') {
            this.closeConnectInboxDialog();

            const currentUserEmail = prefillEmail || this.getUser().get('emailAddress') || '';
            const overlay = document.createElement('div');
            overlay.className = 'nexa-interaction-overlay nexa-connect-inbox-overlay';
            overlay.innerHTML = `
                <section class="nexa-interaction-dialog nexa-connect-inbox-dialog" role="dialog" aria-modal="true" aria-labelledby="nexa-connect-inbox-form-title">
                    <header>
                        <div><p>Email</p><h2 id="nexa-connect-inbox-form-title">Set up your email account</h2></div>
                        <button type="button" class="nexa-dialog-close" data-nexa-connect-inbox-close aria-label="Close">
                            <span class="fas fa-times" aria-hidden="true"></span>
                        </button>
                    </header>
                    <form data-nexa-connect-inbox-form>
                        <div data-nexa-connect-inbox-error></div>
                        <label><span>Email address <small>(your profile email — change it in your profile to connect a different address)</small></span>
                            <input type="email" class="form-control" name="emailAddress" value="${this.escape(currentUserEmail)}" readonly required>
                        </label>
                        <div class="nexa-interaction-grid">
                            <label><span>Username <small>(optional — defaults to your email)</small></span>
                                <input type="text" class="form-control" name="username">
                            </label>
                            <label><span>Password</span>
                                <input type="password" class="form-control" name="password" required autocomplete="new-password">
                            </label>
                        </div>
                        <h4 class="nexa-connect-inbox-section-title">Incoming mail (IMAP)</h4>
                        <div class="nexa-interaction-grid">
                            <label><span>Server</span>
                                <input type="text" class="form-control" name="host" placeholder="imap.yourdomain.com" required>
                            </label>
                            <label><span>Port</span>
                                <input type="number" class="form-control" name="port" value="993" required>
                            </label>
                        </div>
                        <label><span>Security</span>
                            <select class="form-control" name="security">
                                <option value="SSL" selected>SSL</option>
                                <option value="TLS">TLS</option>
                                <option value="">None</option>
                            </select>
                        </label>
                        <h4 class="nexa-connect-inbox-section-title">Outgoing mail (SMTP)</h4>
                        <div class="nexa-interaction-grid">
                            <label><span>Server</span>
                                <input type="text" class="form-control" name="smtpHost" placeholder="smtp.yourdomain.com" required>
                            </label>
                            <label><span>Port</span>
                                <input type="number" class="form-control" name="smtpPort" value="587" required>
                            </label>
                        </div>
                        <label><span>Security</span>
                            <select class="form-control" name="smtpSecurity">
                                <option value="TLS" selected>TLS</option>
                                <option value="SSL">SSL</option>
                                <option value="">None</option>
                            </select>
                        </label>
                        <footer>
                            <button type="button" class="btn btn-default" data-nexa-connect-inbox-close>Cancel</button>
                            <button type="submit" class="btn btn-primary" data-nexa-connect-inbox-submit>
                                <span class="fas fa-plug" aria-hidden="true"></span> Connect inbox
                            </button>
                        </footer>
                    </form>
                </section>`;

            document.body.append(overlay);
            this.connectInboxDialog = overlay;
            this.wireConnectInboxDialogChrome(overlay);

            const form = overlay.querySelector('[data-nexa-connect-inbox-form]');
            form.addEventListener('submit', event => {
                event.preventDefault();
                this.submitConnectInboxForm(form, overlay);
            });
        }

        async submitConnectInboxForm(form, overlay) {
            const errorBox = overlay.querySelector('[data-nexa-connect-inbox-error]');
            const submitButton = overlay.querySelector('[data-nexa-connect-inbox-submit]');
            const submitIcon = submitButton.querySelector('span');
            errorBox.innerHTML = '';

            const data = new FormData(form);
            const emailAddress = String(data.get('emailAddress') || '').trim();
            const password = String(data.get('password') || '');
            const username = String(data.get('username') || '').trim() || emailAddress;
            const host = String(data.get('host') || '').trim();
            const port = Number(data.get('port')) || 993;
            const security = String(data.get('security') || '');
            const smtpHost = String(data.get('smtpHost') || '').trim();
            const smtpPort = Number(data.get('smtpPort')) || 587;
            const smtpSecurity = String(data.get('smtpSecurity') || '');

            if (!emailAddress || !password || !host || !smtpHost) {
                errorBox.innerHTML = `<p class="nexa-interaction-error">Please fill in your email address, password, and both mail servers.</p>`;
                return;
            }

            submitButton.disabled = true;
            submitIcon.className = 'fas fa-circle-notch fa-spin';

            const suppressGlobalError = {error: xhr => { xhr.errorIsHandled = true; }};

            try {
                await Espo.Ajax.postRequest('EmailAccount/action/testConnection', {
                    emailAddress, username, password, host, port, security,
                }, suppressGlobalError);
            } catch (error) {
                submitButton.disabled = false;
                submitIcon.className = 'fas fa-plug';
                errorBox.innerHTML = `<p class="nexa-interaction-error">Couldn't connect with those settings. Double-check your password and server details, then try again.</p>`;
                return;
            }

            try {
                const created = await Espo.Ajax.postRequest('EmailAccount', {
                    name: emailAddress,
                    emailAddress,
                    assignedUserId: this.getUser().id,
                    status: 'Active',
                    useImap: true,
                    host, port, security, username, password,
                    useSmtp: true,
                    smtpHost, smtpPort, smtpSecurity,
                    smtpUsername: username,
                    smtpPassword: password,
                    smtpAuth: true,
                }, suppressGlobalError);
                this.mailboxConnected = true;
                this.connectedMailboxAddress = emailAddress;
                Espo.Ui.success('Inbox connected.');
                this.closeConnectInboxDialog();
                if (created?.id) this.openContactEmailComposer();
            } catch (error) {
                submitButton.disabled = false;
                submitIcon.className = 'fas fa-plug';
                errorBox.innerHTML = `<p class="nexa-interaction-error">Connection looked good, but saving failed. Please try again.</p>`;
            }
        }

        async openMeetingModal() {
            if (!this.getAcl().checkScope('Meeting', 'create')) {
                Espo.Ui.error('You do not have permission to schedule meetings.');
                return;
            }

            const contactName = this.model.get('name') || 'Contact';
            const attributes = {
                assignedUserId: this.getUser().id,
                assignedUserName: this.getUser().get('name'),
            };
            if (this.getConfig().get('b2cMode') || !this.model.get('accountId')) {
                attributes.parentType = 'Contact';
                attributes.parentId = this.model.id;
                attributes.parentName = contactName;
            } else {
                attributes.parentType = 'Account';
                attributes.parentId = this.model.get('accountId');
                attributes.parentName = this.model.get('accountName');
            }

            const meetingLink = this.model.defs?.links?.meetings;
            const relate = meetingLink?.foreign ? {model: this.model, link: meetingLink.foreign} : null;

            try {
                const helper = new RecordModalHelper();
                await helper.showCreate(this, {
                    entityType: 'Meeting',
                    relate,
                    attributes,
                    focusForCreate: true,
                    afterSave: () => {
                        this.model.trigger('update-related:meetings');
                        this.model.trigger('after:relate');
                        this.loadContactMeetings(this.element.querySelector('[data-nexa-contact-workspace]'));
                    },
                });
            } catch (error) {
                Espo.Ui.error(this.translate('Error occurred'));
            }
        }

        async openScheduleCallModal() {
            if (!this.getAcl().checkScope('Call', 'create')) {
                Espo.Ui.error('You do not have permission to schedule calls.');
                return;
            }

            const contactName = this.model.get('name') || 'Contact';
            const attributes = {
                assignedUserId: this.getUser().id,
                assignedUserName: this.getUser().get('name'),
                status: 'Planned',
                direction: 'Outbound',
            };
            if (this.getConfig().get('b2cMode') || !this.model.get('accountId')) {
                attributes.parentType = 'Contact';
                attributes.parentId = this.model.id;
                attributes.parentName = contactName;
            } else {
                attributes.parentType = 'Account';
                attributes.parentId = this.model.get('accountId');
                attributes.parentName = this.model.get('accountName');
            }

            const callLink = this.model.defs?.links?.calls;
            const relate = callLink?.foreign ? {model: this.model, link: callLink.foreign} : null;

            try {
                const helper = new RecordModalHelper();
                await helper.showCreate(this, {
                    entityType: 'Call',
                    relate,
                    attributes,
                    focusForCreate: true,
                    afterSave: () => {
                        this.model.trigger('update-related:calls');
                        this.model.trigger('after:relate');
                        this.loadContactCalls(this.element.querySelector('[data-nexa-contact-workspace]'));
                    },
                });
            } catch (error) {
                Espo.Ui.error(this.translate('Error occurred'));
            }
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
                const commentReplies = new Map();
                const interactions = [];
                records.forEach(record => {
                    const post = String(record.post || '');
                    const replyMatch = post.match(/^<!-- nexa-note-reply:([A-Za-z0-9_-]+) -->\s*\n?/);
                    if (replyMatch) {
                        const list = commentReplies.get(replyMatch[1]) || [];
                        list.push({...record, content: post.replace(replyMatch[0], '')});
                        commentReplies.set(replyMatch[1], list);
                        return;
                    }
                    const commentMatch = post.match(/^<!-- nexa-note-comment:([A-Za-z0-9_-]+) -->\s*\n?/);
                    if (commentMatch) {
                        const items = comments.get(commentMatch[1]) || [];
                        items.push({...record, content: post.replace(commentMatch[0], '')});
                        comments.set(commentMatch[1], items);
                        return;
                    }
                    const noteMarker = post.match(/^<!-- nexa-contact-note -->\s*\n?/);
                    if (noteMarker) {
                        notes.push({...record, content: post.replace(noteMarker[0], '')});
                        return;
                    }
                    // Logged interactions (SMS, WhatsApp, calls, meetings, etc. - see
                    // saveInteraction) are stored as plain stream posts starting with
                    // "[Channel - Direction]" ("[Channel]" for meetings, which have no
                    // direction), so the timeline can show them without a dedicated marker.
                    const interactionMatch = post.match(/^\[([^\]]+?)(?: - (Outbound|Inbound))?]\s*(.*)/);
                    if (interactionMatch) {
                        const bodyLines = post.split('\n').slice(1);
                        const fields = [];
                        let index = 0;
                        while (index < bodyLines.length && bodyLines[index].trim() !== '') {
                            const fieldMatch = bodyLines[index].match(/^([A-Za-z ]+):\s*(.*)$/);
                            if (fieldMatch) fields.push([fieldMatch[1].trim(), fieldMatch[2].trim()]);
                            index += 1;
                        }
                        while (index < bodyLines.length && bodyLines[index].trim() === '') index += 1;
                        interactions.push({
                            ...record,
                            channelLabel: interactionMatch[1],
                            direction: interactionMatch[2],
                            subject: interactionMatch[3],
                            fields,
                            notesBody: bodyLines.slice(index).join('\n').trim(),
                        });
                    }
                });

                comments.forEach(list => {
                    list.forEach(comment => {
                        comment.replies = commentReplies.get(comment.id) || [];
                    });
                });

                // Hydrate stream payloads as Note models so the action state uses
                // the same role and ownership checks as authenticated record APIs.
                const canPinNotes = this.getAcl().checkModel(this.model, 'edit') === true;
                await Promise.all(notes.map(async note => {
                    const model = await this.getModelFactory().create('Note');
                    model.set(note);
                    model.id = note.id;
                    note.canDelete = this.getAcl().checkModel(model, 'delete') === true;
                    note.canPin = canPinNotes;
                    note._editable = this.getAcl().checkModel(model, 'edit') === true &&
                        !!this.getAcl().checkField('Note', 'post', 'edit');
                }));
                await Promise.all(interactions.map(async interaction => {
                    const model = await this.getModelFactory().create('Note');
                    model.set(interaction);
                    model.id = interaction.id;
                    interaction.canDelete = this.getAcl().checkModel(model, 'delete') === true;
                    interaction.canPin = canPinNotes;
                }));

                this.contactNoteRecords = notes;
                this.contactNoteComments = comments;
                this.contactInteractionRecords = interactions;
                this.knownContactNoteIds = this.knownContactNoteIds || new Set();
                notes.forEach(note => {
                    if (this.knownContactNoteIds.has(note.id)) return;
                    this.knownContactNoteIds.add(note.id);
                    this.collapsedContactNoteIds?.add(note.id);
                });
                this.renderContactNotes(workspace, newestId);
                this.renderContactActivities(workspace);
                this.renderContactOverviewInsights(workspace);
                this.loadContactNoteOwners(workspace);
            } catch (error) {
                list.innerHTML = `<div class="nexa-note-empty is-error"><span class="fas fa-exclamation-circle" aria-hidden="true"></span><div><strong>Notes unavailable</strong><p>Refresh the page to try loading them again.</p></div></div>`;
            }
        }

        async ensureTenantOwnersLoaded() {
            if (this.contactNoteTenantOwners) return this.contactNoteTenantOwners;
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
            return this.contactNoteTenantOwners;
        }

        mountAssigneePicker(host, {userId = '', userName = '', onChange} = {}) {
            if (!host) return null;
            const users = this.contactNoteTenantOwners || [];

            host.innerHTML = `
                <div class="nexa-assignee-picker" data-nexa-assignee-picker>
                    <button type="button" class="nexa-assignee-picker-toggle" data-nexa-assignee-toggle aria-haspopup="true" aria-expanded="false">
                        <span data-nexa-assignee-label>${userName ? this.escape(userName) : 'Choose assignee'}</span>
                        <span class="fas fa-caret-down" aria-hidden="true"></span>
                    </button>
                    <div class="nexa-note-filter-menu nexa-note-owner-menu" data-nexa-assignee-menu hidden>
                        <label><span class="fas fa-search" aria-hidden="true"></span><input type="search" data-nexa-assignee-search placeholder="Search people" aria-label="Search people"></label>
                        <div data-nexa-assignee-options>
                            ${users.length ? users.map(user => `<button type="button" data-nexa-assignee-option="${this.escape(user.id)}" data-nexa-owner-search="${this.escape(user.name.toLowerCase())}"${user.id === userId ? ' aria-selected="true"' : ''}>${this.escape(user.name)}</button>`).join('') : '<p>No people found</p>'}
                        </div>
                    </div>
                </div>`;

            const picker = host.querySelector('[data-nexa-assignee-picker]');
            const toggle = picker.querySelector('[data-nexa-assignee-toggle]');
            const menu = picker.querySelector('[data-nexa-assignee-menu]');
            const label = picker.querySelector('[data-nexa-assignee-label]');
            const search = picker.querySelector('[data-nexa-assignee-search]');

            const close = () => {
                menu.hidden = true;
                toggle.setAttribute('aria-expanded', 'false');
            };

            toggle.addEventListener('click', event => {
                event.stopPropagation();
                const opening = menu.hidden;
                menu.hidden = !opening;
                toggle.setAttribute('aria-expanded', String(opening));
                if (opening) window.setTimeout(() => search?.focus(), 0);
            });

            search?.addEventListener('input', () => {
                const term = search.value.trim().toLowerCase();
                picker.querySelectorAll('[data-nexa-assignee-option]').forEach(button => {
                    button.hidden = Boolean(term) && !button.dataset.nexaOwnerSearch.includes(term);
                });
            });

            picker.querySelectorAll('[data-nexa-assignee-option]').forEach(button => {
                button.addEventListener('click', () => {
                    const id = button.dataset.nexaAssigneeOption;
                    const name = button.textContent.trim();
                    label.textContent = name;
                    picker.querySelectorAll('[data-nexa-assignee-option]').forEach(option => option.removeAttribute('aria-selected'));
                    button.setAttribute('aria-selected', 'true');
                    close();
                    onChange?.(id, name);
                });
            });

            const documentHandler = event => {
                if (!picker.contains(event.target)) close();
            };
            document.addEventListener('click', documentHandler);

            return {
                element: picker,
                destroy: () => document.removeEventListener('click', documentHandler),
            };
        }

        async loadContactNoteOwners(workspace) {
            const noteAuthors = (this.contactNoteRecords || []).map(note => ({
                id: note.createdById,
                name: note.createdByName || 'Removed owner',
                isActive: true,
            })).filter(owner => owner.id);

            await this.ensureTenantOwnersLoaded();

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
            this.cancelNoteFieldEdit();
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
            this.bindContactNoteList(list, 'notes');
        }

        collectContactActivities(workspace = null) {
            workspace = workspace || this.element.querySelector('[data-nexa-contact-workspace]');
            const panels = workspace?.querySelector('[data-nexa-activity-panels]');
            if (!panels) return [];
            const activities = [...(this.contactCommunicationActivities || [])];

            activities.forEach(activity => {
                if (!this.knownContactActivityIds.has(activity.id)) {
                    this.knownContactActivityIds.add(activity.id);
                    this.collapsedContactActivityIds.add(activity.id);
                }
            });

            panels.querySelectorAll('[data-nexa-activity-source]').forEach(panel => {
                const source = panel.dataset.nexaActivitySource;
                this.contactActivityRows(panel).forEach(row => {
                    // Communication preferences come from the authoritative tenant-scoped
                    // audit endpoint, and tasks/meetings/notes/logged interactions are all
                    // sourced directly from their own structured records below (avoids the
                    // native panel's own pagination and a fragile text heuristic).
                    if (source === 'stream' || source === 'tasks') return;

                    const id = `${source}-${row.dataset.id}`;
                    const text = row.textContent.replace(/\s+/g, ' ').trim();
                    const date = this.contactActivityRowDate(row);
                    const type = this.contactActivityType(row, source);
                    if (type === 'meeting') return;
                    const link = row.querySelector('a[href*="/view/"]');
                    const title = type === 'preference'
                        ? (text.includes('restriction removed') ? 'Communication restriction removed' : 'Communication restriction set')
                        : (link?.textContent?.replace(/\s+/g, ' ').trim() || this.contactActivityTypeLabel(type));

                    if (!this.knownContactActivityIds.has(id)) {
                        this.knownContactActivityIds.add(id);
                        this.collapsedContactActivityIds.add(id);
                    }
                    activities.push({id, type, title, text, date, href: link?.getAttribute('href') || ''});
                });
            });

            (this.contactTaskRecords || []).forEach(task => {
                const text = [task.name, task.assignedUserName, task.description ? this.contactNotePreview(task.description) : '']
                    .filter(Boolean).join(' ');
                activities.push({
                    id: `tasks-${task.id}`,
                    type: 'task',
                    title: task.name,
                    text,
                    date: this.contactNoteDate(task.dateEnd || task.createdAt),
                    href: '',
                    taskRef: task,
                });
            });

            (this.contactMeetingRecords || []).forEach(meeting => {
                const text = [meeting.name, meeting.assignedUserName, meeting.status].filter(Boolean).join(' ');
                activities.push({
                    id: `meetings-${meeting.id}`,
                    type: 'meeting',
                    title: meeting.name,
                    text,
                    date: this.contactNoteDate(meeting.dateStart || meeting.createdAt),
                    href: '',
                    meetingRef: meeting,
                });
            });

            (this.contactEmailRecords || []).forEach(email => {
                const subjectLine = email.subject || email.name || 'No subject';
                const {rawDate} = this.emailDisplayInfo(email);
                activities.push({
                    id: `email-${email.id}`,
                    type: 'email',
                    title: subjectLine,
                    text: `${subjectLine} ${email.fromString || ''} ${email.to || ''}`,
                    date: this.contactNoteDate(rawDate),
                    href: '',
                    emailRef: email,
                });
            });

            (this.contactNoteRecords || []).forEach(note => {
                const preview = this.contactNotePreview(note.content);
                activities.push({
                    id: `note-${note.id}`,
                    type: 'note',
                    title: preview,
                    text: preview,
                    date: this.contactNoteDate(note.createdAt),
                    href: '',
                    actor: note.createdByName || '',
                    noteId: note.id,
                    isPinned: note.isPinned === true,
                    canPin: note.canPin === true,
                    canDelete: note.canDelete === true,
                });
            });

            (this.contactInteractionRecords || []).forEach(interaction => {
                // 'task' and 'meeting' are reserved for real Task/Meeting records that
                // carry a taskRef/meetingRef (see contactTaskCard/contactMeetingCard
                // below) - a logged interaction never has one, so it must never be
                // mapped to those two types or the card renderer crashes.
                const typeMap = {Call: 'call', Email: 'email'};
                const type = typeMap[interaction.channelLabel] || 'other';
                activities.push({
                    id: `interaction-${interaction.id}`,
                    type,
                    title: `${interaction.channelLabel} logged`,
                    text: interaction.subject || interaction.channelLabel,
                    date: this.contactNoteDate(interaction.createdAt),
                    href: '',
                    actor: interaction.createdByName || '',
                    noteId: interaction.id,
                    isPinned: interaction.isPinned === true,
                    canPin: interaction.canPin === true,
                    canDelete: interaction.canDelete === true,
                    interactionSubject: interaction.subject,
                    interactionFields: interaction.fields || [],
                    interactionNotes: interaction.notesBody || '',
                });
            });

            return activities.sort((left, right) => (right.date?.getTime() || 0) - (left.date?.getTime() || 0));
        }

        async loadContactCommunicationActivities(workspace = null) {
            workspace = workspace || this.element.querySelector('[data-nexa-contact-workspace]');
            this.contactCommunicationActivities = [];

            try {
                const payload = await Espo.Ajax.getRequest(
                    `Nexa/contact/${encodeURIComponent(this.model.id)}/communication-preferences`
                );
                const reasonLabels = {
                    contact_request: 'Contact requested no communication', unsubscribed: 'Unsubscribed',
                    invalid_details: 'Invalid contact details', legal_compliance: 'Legal or compliance requirement',
                    complaint: 'Complaint', consent_restored: 'Consent restored',
                    correction: 'Previous restriction was incorrect', other: 'Other',
                };
                const channelLabels = {email: 'Email', phone: 'Phone', sms: 'SMS', whatsapp: 'WhatsApp', postal: 'Postal mail'};

                this.contactCommunicationActivities = (payload.list || []).map(record => {
                    const createdAt = String(record.createdAt || '');
                    const date = createdAt ? new Date(`${createdAt.replace(' ', 'T')}Z`) : null;
                    const channels = (record.channels || []).map(channel => channelLabels[channel] || channel);
                    const title = record.status === 'allowed'
                        ? 'Communication restriction removed'
                        : 'Communication restriction set';
                    const reason = reasonLabels[record.reason] || String(record.reason || 'Not recorded');
                    const actor = record.changedByName || 'Unknown user';
                    const summary = [title, `by ${actor}`, channels.join(', '), reason, record.note || '']
                        .filter(Boolean).join(' ');

                    return {
                        id: `preference-${record.id}`,
                        type: 'preference',
                        title,
                        text: summary,
                        date: date && !Number.isNaN(date.getTime()) ? date : null,
                        href: '',
                        actor,
                        channels,
                        reason,
                        note: record.note || '',
                    };
                });
            } catch (error) {
                this.contactCommunicationActivities = [];
            }

            this.renderContactActivities(workspace);
        }

        filteredContactActivities(workspace = null) {
            const filter = this.contactActivityFilter || {query: '', type: 'all', period: 'all'};
            return this.collectContactActivities(workspace).filter(activity => {
                const matchesType = filter.type === 'all' || filter.type === activity.type;
                const matchesQuery = !filter.query || `${activity.title} ${activity.text}`.toLowerCase().includes(filter.query);
                const matchesPeriod = filter.period === 'all' || !activity.date ||
                    this.contactNoteMatchesPeriod(activity.date.toISOString(), filter.period);
                return matchesType && matchesQuery && matchesPeriod;
            });
        }

        renderContactActivities(workspace = null) {
            workspace = workspace || this.element.querySelector('[data-nexa-contact-workspace]');
            const list = workspace?.querySelector('[data-nexa-activity-list]');
            if (!list) return;
            this.cancelTaskFieldEdit();
            const activities = this.filteredContactActivities(workspace);
            const groups = new Map();

            activities.forEach(activity => {
                const date = activity.date;
                const key = date ? `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}` : 'unknown';
                const label = date
                    ? new Intl.DateTimeFormat(undefined, {month: 'long', year: 'numeric'}).format(date)
                    : 'Undated';
                if (!groups.has(key)) groups.set(key, {label, activities: []});
                groups.get(key).activities.push(activity);
            });

            list.innerHTML = [...groups.values()].map(group => `
                <section class="nexa-activity-month"><h4>${this.escape(group.label)}</h4>
                    ${group.activities.map(activity => {
                        if (activity.type === 'task' && activity.taskRef) return this.contactTaskCard(activity.taskRef, false, 'activity');
                        if (activity.type === 'meeting' && activity.meetingRef) return this.contactMeetingCard(activity.meetingRef, false, 'activity');
                        if (activity.type === 'email' && activity.emailRef) return this.contactEmailCard(activity.emailRef, false, 'activity');
                        return this.contactActivityCard(activity);
                    }).join('')}
                </section>`).join('');
            list.hidden = activities.length === 0;

            const count = workspace.querySelector('[data-nexa-activity-count]');
            const empty = workspace.querySelector('[data-nexa-activity-empty]');
            const collapse = workspace.querySelector('[data-nexa-collapse-activities]');
            const allCollapsed = activities.length > 0 && activities.every(activity => {
                if (activity.type === 'task' && activity.taskRef) return this.collapsedContactTaskIds?.has(activity.taskRef.id);
                if (activity.type === 'meeting' && activity.meetingRef) return this.collapsedContactMeetingIds?.has(activity.meetingRef.id);
                if (activity.type === 'email' && activity.emailRef) return this.collapsedContactEmailIds?.has(activity.emailRef.id);
                return this.collapsedContactActivityIds.has(activity.id);
            });
            if (count) count.textContent = `${activities.length} ${activities.length === 1 ? 'activity' : 'activities'}`;
            if (empty) empty.hidden = activities.length !== 0;
            if (collapse) collapse.firstChild.textContent = allCollapsed ? 'Expand all ' : 'Collapse all ';

            list.querySelectorAll('[data-nexa-activity-toggle]').forEach(button => {
                button.addEventListener('click', () => {
                    const id = button.closest('[data-nexa-activity-id]').dataset.nexaActivityId;
                    if (this.collapsedContactActivityIds.has(id)) this.collapsedContactActivityIds.delete(id);
                    else this.collapsedContactActivityIds.add(id);
                    this.renderContactActivities(workspace);
                });
            });
            list.querySelectorAll('[data-nexa-activity-actions-toggle]').forEach(button => {
                button.addEventListener('click', event => {
                    event.stopPropagation();
                    const menu = button.parentElement.querySelector('[data-nexa-activity-actions-menu]');
                    const opening = menu.hidden;
                    list.querySelectorAll('[data-nexa-activity-actions-menu]').forEach(item => item.hidden = true);
                    list.querySelectorAll('[data-nexa-activity-actions-toggle]').forEach(item => item.setAttribute('aria-expanded', 'false'));
                    menu.hidden = !opening;
                    button.setAttribute('aria-expanded', String(opening));
                });
            });
            list.querySelectorAll('[data-nexa-activity-pin]').forEach(button => {
                button.addEventListener('click', () => {
                    const noteId = button.closest('[data-nexa-activity-note-id]').dataset.nexaActivityNoteId;
                    const record = this.findActivityNoteRecord(noteId);
                    this.toggleActivityNotePinned(noteId, !record?.isPinned, button);
                });
            });
            list.querySelectorAll('[data-nexa-activity-delete]').forEach(button => {
                button.addEventListener('click', () => this.openActivityDeleteDialog(button.closest('[data-nexa-activity-note-id]').dataset.nexaActivityNoteId));
            });
            if (this.activityActionsDocumentHandler) document.removeEventListener('click', this.activityActionsDocumentHandler);
            this.activityActionsDocumentHandler = event => {
                if (event.target.closest('[data-nexa-activity-actions]')) return;
                this.element.querySelectorAll('[data-nexa-activity-actions-menu]').forEach(menu => menu.hidden = true);
                this.element.querySelectorAll('[data-nexa-activity-actions-toggle]').forEach(button => button.setAttribute('aria-expanded', 'false'));
            };
            document.addEventListener('click', this.activityActionsDocumentHandler);
            this.bindContactTaskList(list, 'activity');
            this.bindContactMeetingList(list, 'activity');
            this.bindContactEmailList(list, 'activity');
            this.bindContactActivityComments(list);
        }

        bindContactActivityComments(list) {
            list.querySelectorAll('[data-nexa-activity-comment-toggle]').forEach(button => {
                button.addEventListener('click', () => {
                    const card = button.closest('[data-nexa-activity-note-id]');
                    const noteId = card.dataset.nexaActivityNoteId;
                    const form = card.querySelector('[data-nexa-activity-comment-form]');
                    form.hidden = false;
                    this.mountContactCommentEditor(noteId, form, 'activity');
                });
            });
            list.querySelectorAll('[data-nexa-activity-comment-cancel]').forEach(button => {
                button.addEventListener('click', () => {
                    const form = button.closest('[data-nexa-activity-comment-form]');
                    const noteId = form.closest('[data-nexa-activity-note-id]').dataset.nexaActivityNoteId;
                    this.clearContactCommentEditor(noteId, 'activity');
                    form.hidden = true;
                });
            });
            list.querySelectorAll('[data-nexa-activity-comment-form]').forEach(form => {
                form.addEventListener('submit', event => {
                    event.preventDefault();
                    const noteId = form.closest('[data-nexa-activity-note-id]').dataset.nexaActivityNoteId;
                    this.saveActivityComment(noteId, form);
                });
            });
            list.querySelectorAll('[data-nexa-activity-reply-toggle]').forEach(button => {
                button.addEventListener('click', () => {
                    const commentEl = button.closest('[data-nexa-activity-comment-id]');
                    const commentId = commentEl.dataset.nexaActivityCommentId;
                    const form = commentEl.querySelector('[data-nexa-activity-reply-form]');
                    form.hidden = false;
                    this.mountContactCommentEditor(`reply-${commentId}`, form, 'activity');
                });
            });
            list.querySelectorAll('[data-nexa-activity-reply-cancel]').forEach(button => {
                button.addEventListener('click', () => {
                    const form = button.closest('[data-nexa-activity-reply-form]');
                    const commentId = form.closest('[data-nexa-activity-comment-id]').dataset.nexaActivityCommentId;
                    this.clearContactCommentEditor(`reply-${commentId}`, 'activity');
                    form.hidden = true;
                });
            });
            list.querySelectorAll('[data-nexa-activity-reply-form]').forEach(form => {
                form.addEventListener('submit', event => {
                    event.preventDefault();
                    const noteId = form.closest('[data-nexa-activity-note-id]').dataset.nexaActivityNoteId;
                    const commentId = form.closest('[data-nexa-activity-comment-id]').dataset.nexaActivityCommentId;
                    this.saveActivityReply(noteId, commentId, form);
                });
            });
            list.querySelectorAll('[data-nexa-activity-comments-visibility-toggle]').forEach(button => {
                button.addEventListener('click', () => {
                    const noteId = button.closest('[data-nexa-activity-note-id]').dataset.nexaActivityNoteId;
                    this.activityCommentsVisibleIds = this.activityCommentsVisibleIds || new Set();
                    if (this.activityCommentsVisibleIds.has(noteId)) this.activityCommentsVisibleIds.delete(noteId);
                    else this.activityCommentsVisibleIds.add(noteId);
                    this.renderContactActivities();
                });
            });
            list.querySelectorAll('[data-nexa-activity-replies-visibility-toggle]').forEach(button => {
                button.addEventListener('click', () => {
                    const commentId = button.closest('[data-nexa-activity-comment-id]').dataset.nexaActivityCommentId;
                    this.activityRepliesVisibleIds = this.activityRepliesVisibleIds || new Set();
                    if (this.activityRepliesVisibleIds.has(commentId)) this.activityRepliesVisibleIds.delete(commentId);
                    else this.activityRepliesVisibleIds.add(commentId);
                    this.renderContactActivities();
                });
            });
        }

        async saveActivityComment(noteId, form) {
            if (!noteId || form.dataset.saving === 'true') return;
            const editor = this.contactCommentEditors?.get(`activity-${noteId}`);
            if (!editor) return;
            editor.view.fetchToModel();
            const content = String(editor.model.get('post') || '').trim();
            const error = form.querySelector('[data-nexa-activity-comment-error]');
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
                this.clearContactCommentEditor(noteId, 'activity');
                form.hidden = true;
                this.activityCommentsVisibleIds = this.activityCommentsVisibleIds || new Set();
                this.activityCommentsVisibleIds.add(noteId);
                this.model.trigger('sync');
                await this.loadContactNotes();
            } catch (saveError) {
                error.textContent = 'The comment could not be saved.';
                error.hidden = false;
                form.dataset.saving = 'false';
                form.querySelector('button[type="submit"]').disabled = false;
            }
        }

        async saveActivityReply(noteId, commentId, form) {
            if (!noteId || !commentId || form.dataset.saving === 'true') return;
            const editor = this.contactCommentEditors?.get(`activity-reply-${commentId}`);
            if (!editor) return;
            editor.view.fetchToModel();
            const content = String(editor.model.get('post') || '').trim();
            const error = form.querySelector('[data-nexa-activity-reply-error]');
            if (this.richTextIsEmpty(content)) {
                error.textContent = 'Enter a reply before saving.';
                error.hidden = false;
                return;
            }

            form.dataset.saving = 'true';
            form.querySelector('button[type="submit"]').disabled = true;
            error.hidden = true;
            try {
                await this.createContactStreamNote(`<!-- nexa-note-reply:${commentId} -->\n${content}`);
                this.clearContactCommentEditor(`reply-${commentId}`, 'activity');
                form.hidden = true;
                this.activityCommentsVisibleIds = this.activityCommentsVisibleIds || new Set();
                this.activityCommentsVisibleIds.add(noteId);
                this.activityRepliesVisibleIds = this.activityRepliesVisibleIds || new Set();
                this.activityRepliesVisibleIds.add(commentId);
                this.model.trigger('sync');
                await this.loadContactNotes();
            } catch (saveError) {
                error.textContent = 'The reply could not be saved.';
                error.hidden = false;
                form.dataset.saving = 'false';
                form.querySelector('button[type="submit"]').disabled = false;
            }
        }

        findActivityNoteRecord(noteId) {
            return (this.contactNoteRecords || []).find(item => item.id === noteId) ||
                (this.contactInteractionRecords || []).find(item => item.id === noteId);
        }

        async toggleActivityNotePinned(noteId, pinned, button) {
            if (!noteId || button?.disabled || button?.dataset.saving === 'true') return;
            button.dataset.saving = 'true';
            button.disabled = true;
            try {
                const path = `Note/${encodeURIComponent(noteId)}/pin`;
                if (pinned) await Espo.Ajax.postRequest(path);
                else await Espo.Ajax.deleteRequest(path);
                const record = this.findActivityNoteRecord(noteId);
                if (record) record.isPinned = pinned;
                this.renderContactNotes();
                this.renderContactActivities();
                Espo.Ui.success(pinned ? 'Activity pinned' : 'Activity unpinned');
            } catch (error) {
                button.disabled = false;
                button.dataset.saving = 'false';
                Espo.Ui.error('The activity could not be updated. Check your access and try again.');
            }
        }

        openActivityDeleteDialog(noteId) {
            const record = this.findActivityNoteRecord(noteId);
            if (!record?.canDelete) return;
            this.closeActivityDeleteDialog();
            const overlay = document.createElement('div');
            overlay.className = 'nexa-note-delete-overlay';
            overlay.dataset.nexaActivityDeleteDialog = 'true';
            overlay.innerHTML = `<section class="nexa-note-delete-dialog" role="dialog" aria-modal="true" aria-labelledby="nexa-activity-delete-title"><header><div><p>Delete activity</p><h2 id="nexa-activity-delete-title">Delete this activity?</h2></div><button type="button" class="nexa-dialog-close" data-nexa-activity-delete-close aria-label="Close"><span class="fas fa-times" aria-hidden="true"></span></button></header><div class="nexa-note-delete-content"><p>This activity will be removed from the contact timeline. This action cannot be undone from this page.</p><p class="nexa-note-delete-error" role="alert" hidden></p></div><footer><button type="button" class="btn btn-default" data-nexa-activity-delete-cancel>Cancel</button><button type="button" class="btn btn-danger" data-nexa-activity-delete-confirm><span class="far fa-trash-alt" aria-hidden="true"></span><span>Delete activity</span></button></footer></section>`;
            document.body.append(overlay);
            this.activityDeleteDialog = overlay;
            const close = () => this.closeActivityDeleteDialog();
            overlay.querySelector('[data-nexa-activity-delete-close]').addEventListener('click', close);
            overlay.querySelector('[data-nexa-activity-delete-cancel]').addEventListener('click', close);
            overlay.addEventListener('click', event => { if (event.target === overlay) close(); });
            overlay.addEventListener('keydown', event => { if (event.key === 'Escape') close(); });
            overlay.querySelector('[data-nexa-activity-delete-confirm]').addEventListener('click', event => this.deleteActivityNote(noteId, event.currentTarget));
            window.setTimeout(() => overlay.querySelector('[data-nexa-activity-delete-cancel]')?.focus(), 0);
        }

        closeActivityDeleteDialog() {
            this.activityDeleteDialog?.remove();
            this.activityDeleteDialog = null;
        }

        async deleteActivityNote(noteId, button) {
            if (!noteId || button.dataset.saving === 'true') return;
            const error = this.activityDeleteDialog?.querySelector('.nexa-note-delete-error');
            button.dataset.saving = 'true';
            button.disabled = true;
            button.classList.add('is-loading');
            try {
                await Espo.Ajax.deleteRequest(`Note/${encodeURIComponent(noteId)}`);
                this.contactNoteRecords = (this.contactNoteRecords || []).filter(item => item.id !== noteId);
                this.contactInteractionRecords = (this.contactInteractionRecords || []).filter(item => item.id !== noteId);
                this.contactNoteComments?.delete(noteId);
                this.collapsedContactNoteIds?.delete(noteId);
                this.collapsedContactActivityIds?.delete(`note-${noteId}`);
                this.collapsedContactActivityIds?.delete(`interaction-${noteId}`);
                this.closeActivityDeleteDialog();
                this.renderContactNotes();
                this.renderContactActivities();
                Espo.Ui.success('Activity deleted');
            } catch (deleteError) {
                if (error) {
                    error.textContent = 'The activity could not be deleted. Check your permission and try again.';
                    error.hidden = false;
                }
                button.dataset.saving = 'false';
                button.disabled = false;
                button.classList.remove('is-loading');
            }
        }

        contactActivityCard(activity) {
            const collapsed = this.collapsedContactActivityIds.has(activity.id);
            const icon = this.contactActivityTypeIcon(activity.type);
            const timestamp = activity.date
                ? new Intl.DateTimeFormat(undefined, {dateStyle: 'medium', timeStyle: 'short'}).format(activity.date)
                : 'Date not recorded';
            const preview = activity.text || activity.title;
            const subtitle = activity.actor
                ? `${this.contactActivityTypeLabel(activity.type)} by ${activity.actor}`
                : this.contactActivityTypeLabel(activity.type);
            const details = activity.type === 'preference'
                ? `<dl class="nexa-activity-audit-details">
                    <div><dt>Changed by</dt><dd>${this.escape(activity.actor)}</dd></div>
                    <div><dt>Channels</dt><dd>${this.escape(activity.channels.join(', ') || 'Not recorded')}</dd></div>
                    <div><dt>Reason</dt><dd>${this.escape(activity.reason)}</dd></div>
                    ${activity.note ? `<div class="is-note"><dt>Internal note</dt><dd>${this.escape(activity.note)}</dd></div>` : ''}
                </dl>`
                : activity.interactionFields
                    ? `<dl class="nexa-activity-audit-details">
                        <div><dt>Subject</dt><dd>${this.escape(activity.interactionSubject || activity.title)}</dd></div>
                        ${activity.interactionFields.map(([label, value]) => `<div><dt>${this.escape(label)}</dt><dd>${this.escape(value)}</dd></div>`).join('')}
                        ${activity.interactionNotes ? `<div class="is-note"><dt>Notes</dt><dd>${this.escape(activity.interactionNotes)}</dd></div>` : ''}
                    </dl>`
                    : `<p>${this.escape(preview)}</p>`;
            const deleteHelp = "You don't have permission to delete this activity. Ask your admin to grant permission.";
            const actionsHtml = activity.noteId
                ? `<div class="nexa-note-actions" data-nexa-activity-actions${collapsed ? ' hidden' : ''}><button type="button" class="nexa-note-actions-toggle" data-nexa-activity-actions-toggle aria-expanded="false">Actions <span class="fas fa-caret-down" aria-hidden="true"></span></button><div class="nexa-note-actions-menu" data-nexa-activity-actions-menu hidden><button type="button" data-nexa-activity-pin${activity.canPin ? '' : ' disabled aria-disabled="true"'}><span class="fas fa-thumbtack" aria-hidden="true"></span>${activity.isPinned ? 'Unpin' : 'Pin'}</button>${activity.canDelete ? '<button type="button" class="is-danger" data-nexa-activity-delete><span class="far fa-trash-alt" aria-hidden="true"></span>Delete</button>' : `<span class="nexa-note-action-disabled" data-tooltip="${this.escape(deleteHelp)}" tabindex="0"><button type="button" class="is-danger" disabled aria-disabled="true"><span class="far fa-trash-alt" aria-hidden="true"></span>Delete</button></span>`}</div></div>`
                : '';
            const commentsHtml = activity.noteId ? this.contactActivityCommentsSection(activity) : '';

            return `<article class="nexa-activity-card${collapsed ? ' is-collapsed' : ''}${activity.isPinned ? ' is-pinned' : ''}" data-nexa-activity-id="${this.escape(activity.id)}"${activity.noteId ? ` data-nexa-activity-note-id="${this.escape(activity.noteId)}"` : ''}>
                <header><button type="button" class="nexa-activity-toggle" data-nexa-activity-toggle aria-expanded="${!collapsed}"><span class="fas fa-chevron-${collapsed ? 'right' : 'down'}" aria-hidden="true"></span><span class="fas nexa-activity-icon ${icon}" aria-hidden="true"></span><span class="nexa-activity-heading"><strong>${activity.isPinned ? '<span class="fas fa-thumbtack nexa-note-pinned-icon" aria-label="Pinned activity"></span> ' : ''}${this.escape(activity.title)}</strong><span>${this.escape(subtitle)}</span></span></button><div class="nexa-note-header-meta">${actionsHtml}<time>${this.escape(timestamp)}</time></div></header>
                <p class="nexa-activity-preview"${collapsed ? '' : ' hidden'}>${this.escape(preview)}</p>
                <div class="nexa-activity-details"${collapsed ? ' hidden' : ''}>${details}${activity.href ? `<a href="${this.escape(activity.href)}">View record <span class="fas fa-arrow-right" aria-hidden="true"></span></a>` : ''}${commentsHtml}</div>
            </article>`;
        }

        contactActivityCommentsSection(activity) {
            const noteId = activity.noteId;
            const comments = this.contactNoteComments?.get(noteId) || [];
            const commentsVisible = this.activityCommentsVisibleIds?.has(noteId) === true;
            const canComment = this.getAcl().checkScope('Note', 'create') === true;
            const editorHost = `activity-${noteId}`;

            return `<footer>
                    ${canComment ? '<button type="button" data-nexa-activity-comment-toggle><span class="far fa-comment" aria-hidden="true"></span><span>Add comment</span></button>' : ''}
                    ${comments.length ? `<button type="button" class="nexa-task-reply-toggle" data-nexa-activity-comments-visibility-toggle>${commentsVisible ? 'Hide comments' : `Show comments (${comments.length})`}</button>` : '<span>0 comments</span>'}
                </footer>
                <div class="nexa-note-comments"${commentsVisible ? '' : ' hidden'}>${comments.map(comment => this.contactActivityComment(comment)).join('')}</div>
                <form class="nexa-note-comment-form" data-nexa-activity-comment-form hidden>
                    <div class="nexa-native-rich-editor nexa-comment-editor" data-nexa-comment-editor-host="${this.escape(editorHost)}" aria-label="Comment"><div class="nexa-note-editor-loading"><span class="fas fa-circle-notch fa-spin" aria-hidden="true"></span><span>Loading editor</span></div></div>
                    <div><button type="button" class="btn btn-default btn-xs" data-nexa-activity-comment-cancel>Cancel</button><button type="submit" class="btn btn-primary btn-xs">Comment</button></div>
                    <p role="alert" data-nexa-activity-comment-error hidden></p>
                </form>`;
        }

        contactActivityComment(comment) {
            const author = comment.createdByName || 'Team member';
            const createdAt = comment.createdAt ? this.getDateTime().toDisplay(comment.createdAt) : '';
            const replies = comment.replies || [];
            const repliesVisible = this.activityRepliesVisibleIds?.has(comment.id) === true;
            const canComment = this.getAcl().checkScope('Note', 'create') === true;
            const replyEditorHost = `activity-reply-${comment.id}`;

            return `<div class="nexa-note-comment" data-nexa-activity-comment-id="${this.escape(comment.id)}">
                <div><strong>${this.escape(author)}</strong><time>${this.escape(createdAt)}</time></div>
                <p>${this.formatNoteContent(comment.content)}</p>
                <div class="nexa-task-comment-actions">
                    ${canComment ? '<button type="button" class="nexa-task-reply-toggle" data-nexa-activity-reply-toggle>Reply</button>' : ''}
                    ${replies.length ? `<button type="button" class="nexa-task-reply-toggle" data-nexa-activity-replies-visibility-toggle>${repliesVisible ? 'Hide replies' : `Show replies (${replies.length})`}</button>` : ''}
                </div>
                ${replies.length ? `<div class="nexa-task-comment-replies"${repliesVisible ? '' : ' hidden'}>${replies.map(reply => this.contactActivityReply(reply)).join('')}</div>` : ''}
                <form class="nexa-note-comment-form nexa-task-reply-form" data-nexa-activity-reply-form hidden>
                    <div class="nexa-native-rich-editor nexa-comment-editor" data-nexa-comment-editor-host="${this.escape(replyEditorHost)}" aria-label="Reply"><div class="nexa-note-editor-loading"><span class="fas fa-circle-notch fa-spin" aria-hidden="true"></span><span>Loading editor</span></div></div>
                    <div><button type="button" class="btn btn-default btn-xs" data-nexa-activity-reply-cancel>Cancel</button><button type="submit" class="btn btn-primary btn-xs">Reply</button></div>
                    <p role="alert" data-nexa-activity-reply-error hidden></p>
                </form>
            </div>`;
        }

        contactActivityReply(reply) {
            const author = reply.createdByName || 'Team member';
            const createdAt = reply.createdAt ? this.getDateTime().toDisplay(reply.createdAt) : '';
            return `<div class="nexa-note-comment nexa-task-comment-reply"><div><strong>${this.escape(author)}</strong><time>${this.escape(createdAt)}</time></div><p>${this.formatNoteContent(reply.content)}</p></div>`;
        }

        contactActivityType(row, source) {
            if (source === 'stream') return 'preference';
            const value = `${row.innerHTML} ${row.textContent}`.toLowerCase();
            if (source === 'tasks' || value.includes('#task/') || value.includes(' task')) return 'task';
            if (value.includes('#call/') || value.includes(' call')) return 'call';
            if (value.includes('#meeting/') || value.includes(' meeting')) return 'meeting';
            if (value.includes('#email/') || value.includes(' email')) return 'email';
            return 'other';
        }

        contactActivityTypeLabel(type) {
            return {call: 'Call', meeting: 'Meeting', email: 'Email', task: 'Task', note: 'Note', preference: 'Communication preference', other: 'CRM activity'}[type] || 'CRM activity';
        }

        contactActivityTypeIcon(type) {
            return {call: 'fa-phone-alt', meeting: 'fa-calendar-check', email: 'fa-envelope', task: 'fa-check-square', note: 'fa-sticky-note', preference: 'fa-ban', other: 'fa-history'}[type] || 'fa-history';
        }

        contactActivityRows(panel) {
            return [...panel.querySelectorAll('.list-row[data-id]')].filter(row => {
                return !row.parentElement?.closest('.list-row[data-id]');
            });
        }

        contactActivityRowDate(row) {
            const node = row.querySelector('time[datetime], [data-name="dateStart"] time, [data-name="createdAt"] time, .stream-date-container a, [data-name="dateStart"], [data-name="createdAt"]');
            const value = node?.getAttribute('datetime') || node?.dataset?.value || node?.getAttribute('title') || node?.textContent?.trim();
            if (!value) return null;
            const date = new Date(value);
            return Number.isNaN(date.getTime()) ? null : date;
        }

        contactNoteCard(note, comments, isNewest = false, context = 'notes') {
            const author = note.createdByName || 'Team member';
            const createdAt = note.createdAt ? this.getDateTime().toDisplay(note.createdAt) : '';
            const collapsed = this.collapsedContactNoteIds?.has(note.id);
            const canDelete = note.canDelete === true;
            const canPin = this.getAcl().checkModel(this.model, 'edit') === true;
            const deleteHelp = "You don't have permission to delete this note. Ask your admin to grant permission.";
            const editorHost = `${context}-${note.id}`;
            return `<article class="nexa-note-card${isNewest ? ' is-new' : ''}${collapsed ? ' is-collapsed' : ''}${note.isPinned ? ' is-pinned' : ''}" data-nexa-note-id="${this.escape(note.id)}" data-nexa-note-context="${this.escape(context)}">
                <header>
                    <button class="nexa-note-toggle" type="button" data-nexa-note-toggle aria-expanded="${!collapsed}"><span class="fas fa-chevron-${collapsed ? 'right' : 'down'}" aria-hidden="true"></span>${note.isPinned ? '<span class="fas fa-thumbtack nexa-note-pinned-icon" aria-label="Pinned note"></span>' : ''}<span class="nexa-note-kind">Note</span><span>by <strong>${this.escape(author)}</strong></span>${comments.length ? `<span class="nexa-note-comment-count"><span class="far fa-comment" aria-hidden="true"></span>${comments.length}</span>` : ''}</button>
                    <div class="nexa-note-header-meta"><div class="nexa-note-actions" data-nexa-note-actions${collapsed ? ' hidden' : ''}><button type="button" class="nexa-note-actions-toggle" data-nexa-note-actions-toggle aria-expanded="false">Actions <span class="fas fa-caret-down" aria-hidden="true"></span></button><div class="nexa-note-actions-menu" data-nexa-note-actions-menu hidden><button type="button" data-nexa-note-pin${canPin ? '' : ' disabled aria-disabled="true"'}><span class="fas fa-thumbtack" aria-hidden="true"></span>${note.isPinned ? 'Unpin' : 'Pin'}</button>${canDelete ? '<button type="button" class="is-danger" data-nexa-note-delete><span class="far fa-trash-alt" aria-hidden="true"></span>Delete</button>' : `<span class="nexa-note-action-disabled" data-tooltip="${this.escape(deleteHelp)}" tabindex="0"><button type="button" class="is-danger" disabled aria-disabled="true"><span class="far fa-trash-alt" aria-hidden="true"></span>Delete</button></span>`}</div></div><time>${this.escape(createdAt)}</time></div>
                </header>
                <p class="nexa-note-preview"${collapsed ? '' : ' hidden'}>${this.escape(this.contactNotePreview(note.content))}</p>
                <div class="nexa-note-details" data-nexa-note-details${collapsed ? ' hidden' : ''}>
                    ${this.contactNoteBody(note)}
                    <footer><button type="button" data-nexa-comment-toggle><span class="far fa-comment" aria-hidden="true"></span><span>Add comment</span></button><span>${comments.length} ${comments.length === 1 ? 'comment' : 'comments'}</span></footer>
                    <div class="nexa-note-comments">${comments.map(comment => this.contactNoteComment(comment)).join('')}</div>
                    <form class="nexa-note-comment-form" data-nexa-comment-form hidden>
                        <div class="nexa-native-rich-editor nexa-comment-editor" data-nexa-comment-editor-host="${this.escape(editorHost)}" aria-label="Comment"><div class="nexa-note-editor-loading"><span class="fas fa-circle-notch fa-spin" aria-hidden="true"></span><span>Loading editor</span></div></div>
                        <div><button type="button" class="btn btn-default btn-xs" data-nexa-comment-cancel>Cancel</button><button type="submit" class="btn btn-primary btn-xs">Comment</button></div>
                        <p role="alert" data-nexa-comment-error hidden></p>
                    </form>
                </div>
            </article>`;
        }

        contactNoteBody(note) {
            const bodyHtml = this.formatNoteContent(note.content);
            if (!note._editable) {
                return `<div class="nexa-note-body">${bodyHtml}</div>`;
            }

            return `<div class="nexa-note-body-inline nexa-inline-detail nexa-inline-detail--notes" data-nexa-note-inline-field data-field="post">
                <div class="nexa-note-body" data-nexa-inline-display>${bodyHtml}</div>
                <button type="button" class="nexa-inline-detail-trigger" data-nexa-note-inline-trigger aria-label="Edit note"><span class="fas fa-pencil-alt" aria-hidden="true"></span></button>
            </div>`;
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

        async startNoteFieldEdit(noteId, row) {
            const note = (this.contactNoteRecords || []).find(item => item.id === noteId);
            if (!note || !note._editable) {
                Espo.Ui.error(this.translate('Access denied'));
                return;
            }

            this.cancelNoteFieldEdit();

            const display = row.querySelector('[data-nexa-inline-display]');
            const trigger = row.querySelector('[data-nexa-note-inline-trigger]');
            const originalHtml = display.innerHTML;

            this.noteInlineEditSeq = (this.noteInlineEditSeq || 0) + 1;
            const key = `nexaNoteField${this.noteInlineEditSeq}`;
            const hostId = `nexa-note-inline-host-${this.noteInlineEditSeq}`;

            row.classList.add('is-editing');
            trigger.hidden = true;
            display.innerHTML = `<div class="nexa-task-inline-editor" data-nexa-note-inline-host="${hostId}"></div>
                <div class="nexa-task-inline-actions">
                    <button type="button" class="btn btn-default btn-xs" data-nexa-note-inline-cancel>Cancel</button>
                    <button type="button" class="btn btn-primary btn-xs" data-nexa-note-inline-save>Save</button>
                </div>
                <p class="nexa-note-error" data-nexa-note-inline-error role="alert" hidden></p>`;

            this.noteFieldEditState = {
                noteId, row, display, trigger, originalHtml, key,
                views: [], model: null, saving: false,
            };

            display.querySelector('[data-nexa-note-inline-cancel]').addEventListener('click', () => this.cancelNoteFieldEdit());
            display.querySelector('[data-nexa-note-inline-save]').addEventListener('click', () => this.saveNoteFieldEdit());

            try {
                const model = await this.getModelFactory().create('Note');
                model.id = note.id;
                model.set('post', note.content);
                if (!this.noteFieldEditState || this.noteFieldEditState.key !== key) return;
                this.noteFieldEditState.model = model;

                const view = await this.createView(key, 'views/fields/wysiwyg', {
                    fullSelector: `[data-nexa-note-inline-host="${hostId}"]`,
                    model, name: 'post', mode: 'edit',
                    params: {height: 180, minHeight: 140},
                });
                await view.render();
                if (!this.noteFieldEditState || this.noteFieldEditState.key !== key) return;
                this.noteFieldEditState.views = [view];
            } catch (error) {
                this.cancelNoteFieldEdit();
                Espo.Ui.error(this.translate('Error occurred'));
            }
        }

        async saveNoteFieldEdit() {
            const state = this.noteFieldEditState;
            if (!state || state.saving) return;

            const error = state.display.querySelector('[data-nexa-note-inline-error]');
            error.hidden = true;

            state.views.forEach(view => view.fetchToModel());
            const content = String(state.model.get('post') || '');
            if (this.richTextIsEmpty(content)) {
                error.textContent = 'Enter note content before saving.';
                error.hidden = false;
                return;
            }
            const post = `<!-- nexa-contact-note -->\n${content}`;

            state.saving = true;
            const saveButton = state.display.querySelector('[data-nexa-note-inline-save]');
            saveButton.disabled = true;
            saveButton.classList.add('is-loading');

            try {
                await state.model.save({post}, {patch: true});
                const note = (this.contactNoteRecords || []).find(item => item.id === state.noteId);
                if (note) note.content = content;
                this.cancelNoteFieldEdit({skipRestore: true});
                Espo.Ui.success('Note updated');
                this.renderContactNotes();
                this.renderContactActivities();
            } catch (saveError) {
                error.textContent = 'The note could not be saved. Check your access and try again.';
                error.hidden = false;
                state.saving = false;
                saveButton.disabled = false;
                saveButton.classList.remove('is-loading');
            }
        }

        cancelNoteFieldEdit({skipRestore = false} = {}) {
            const state = this.noteFieldEditState;
            if (!state) return;
            if (this.getView(state.key)) this.clearView(state.key);
            if (!skipRestore && state.row?.isConnected) {
                state.display.innerHTML = state.originalHtml;
                state.row.classList.remove('is-editing');
                if (state.trigger) state.trigger.hidden = false;
            }
            this.noteFieldEditState = null;
        }

        formatNoteContent(content) {
            // All callers (note body, note comments, task notes) are authored via
            // views/fields/wysiwyg, so content is always HTML - including plain
            // single-line entries with no wrapping tag (e.g. an editor-inserted
            // "&nbsp;"), which must never be re-escaped or it renders literally.
            return this.getHelper().sanitizeHtml(String(content || ''));
        }

        bindContactNoteList(list, context = 'notes') {
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
                    this.mountContactCommentEditor(card.dataset.nexaNoteId, form, context);
                });
            });
            list.querySelectorAll('[data-nexa-comment-cancel]').forEach(button => {
                button.addEventListener('click', () => {
                    const form = button.closest('[data-nexa-comment-form]');
                    this.clearContactCommentEditor(form.closest('[data-nexa-note-id]').dataset.nexaNoteId, context);
                    form.hidden = true;
                });
            });
            list.querySelectorAll('[data-nexa-comment-form]').forEach(form => {
                form.addEventListener('submit', event => {
                    event.preventDefault();
                    this.saveNoteComment(form.closest('[data-nexa-note-id]').dataset.nexaNoteId, form);
                });
            });
            list.querySelectorAll('[data-nexa-note-inline-trigger]').forEach(button => {
                button.addEventListener('click', () => {
                    const row = button.closest('[data-nexa-note-inline-field]');
                    const noteId = row.closest('[data-nexa-note-id]').dataset.nexaNoteId;
                    this.startNoteFieldEdit(noteId, row);
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
                this.element.querySelectorAll('[data-nexa-note-actions-menu]').forEach(menu => menu.hidden = true);
                this.element.querySelectorAll('[data-nexa-note-actions-toggle]').forEach(button => button.setAttribute('aria-expanded', 'false'));
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
                this.renderContactActivities();
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
                this.renderContactActivities();
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

        async mountContactCommentEditor(noteId, form, context = 'notes') {
            this.contactCommentEditors = this.contactCommentEditors || new Map();
            const editorId = `${context}-${noteId}`;
            const existing = this.contactCommentEditors.get(editorId);
            if (existing) {
                form.querySelector('.note-editable')?.focus();
                return;
            }

            const submit = form.querySelector('button[type="submit"]');
            submit.disabled = true;
            const key = `nexaCommentEditor-${editorId}`;
            try {
                const model = await this.getModelFactory().create('Note');
                if (!form.isConnected || form.hidden) return;
                const view = await this.createView(key, 'views/fields/wysiwyg', {
                    fullSelector: `[data-nexa-comment-editor-host="${editorId}"]`,
                    model,
                    name: 'post',
                    mode: 'edit',
                    params: {height: 150, minHeight: 120},
                });
                await view.render();
                this.contactCommentEditors.set(editorId, {key, model, view});
                submit.disabled = false;
                window.setTimeout(() => form.querySelector('.note-editable')?.focus(), 0);
            } catch (error) {
                const message = form.querySelector('[data-nexa-comment-error]');
                message.textContent = 'The rich-text editor could not be loaded.';
                message.hidden = false;
            }
        }

        clearContactCommentEditor(noteId, context = 'notes') {
            const editorId = `${context}-${noteId}`;
            const entry = this.contactCommentEditors?.get(editorId);
            if (!entry) return;
            if (this.getView(entry.key)) this.clearView(entry.key);
            this.contactCommentEditors.delete(editorId);
        }

        clearContactCommentEditors(context = null) {
            if (!this.contactCommentEditors) return;
            [...this.contactCommentEditors.entries()].forEach(([editorId, entry]) => {
                if (context && !editorId.startsWith(`${context}-`)) return;
                if (this.getView(entry.key)) this.clearView(entry.key);
                this.contactCommentEditors.delete(editorId);
            });
        }

        async saveNoteComment(noteId, form) {
            if (form.dataset.saving === 'true') return;
            const context = form.closest('[data-nexa-note-context]')?.dataset.nexaNoteContext || 'notes';
            const editor = this.contactCommentEditors?.get(`${context}-${noteId}`);
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

        async openTaskDialog() {
            this.closeTaskDialog();
            const contactName = this.model.get('name') || 'Contact';
            const overlay = document.createElement('div');
            overlay.className = 'nexa-task-overlay';
            overlay.dataset.nexaTaskDialog = 'true';
            overlay.innerHTML = `
                <section class="nexa-task-dialog" role="dialog" aria-modal="true"
                    aria-labelledby="nexa-task-dialog-title" aria-describedby="nexa-task-dialog-help">
                    <header>
                        <div><p>Customer workspace</p><h2 id="nexa-task-dialog-title">Task</h2></div>
                        <button type="button" class="nexa-dialog-close" data-nexa-task-close aria-label="Close task">
                            <span class="fas fa-times" aria-hidden="true"></span>
                        </button>
                    </header>
                    <form data-nexa-task-form>
                        <div class="nexa-note-recipient"><span>For</span><strong>${this.escape(contactName)}</strong></div>
                        <p id="nexa-task-dialog-help" class="nexa-note-help">Create a task for your team to follow up with this customer.</p>

                        <div class="nexa-task-field">
                            <label for="nexa-task-name">Task name</label>
                            <input type="text" id="nexa-task-name" name="name" class="form-control" maxlength="255" required>
                        </div>

                        <div class="nexa-task-field">
                            <label>Activity date &amp; time</label>
                            <div data-nexa-task-dateend-host aria-label="Activity date and time"><div class="nexa-note-editor-loading"><span class="fas fa-circle-notch fa-spin" aria-hidden="true"></span><span>Loading</span></div></div>
                        </div>

                        <div class="nexa-task-field-grid">
                            <div class="nexa-task-field">
                                <label for="nexa-task-reminder">Send reminder</label>
                                <select id="nexa-task-reminder" class="form-control" data-nexa-task-reminder>
                                    <option value="none">No reminder</option>
                                    <option value="at-time">At task due time</option>
                                    <option value="30m">30 minutes before</option>
                                    <option value="1h">1 hour before</option>
                                    <option value="1d">1 day before</option>
                                    <option value="1w">1 week before</option>
                                    <option value="custom">Custom date &amp; time</option>
                                </select>
                            </div>
                            <div class="nexa-task-field" data-nexa-task-remind-at-field hidden>
                                <label for="nexa-task-remind-at">Reminder date &amp; time</label>
                                <input type="datetime-local" id="nexa-task-remind-at" class="form-control" data-nexa-task-remind-at>
                            </div>
                        </div>

                        <div class="nexa-task-field-grid">
                            <div class="nexa-task-field">
                                <label for="nexa-task-type">Task type</label>
                                <select id="nexa-task-type" class="form-control" name="taskType">
                                    <option value="To-Do">To-Do</option>
                                    <option value="Call">Call</option>
                                    <option value="Email">Email</option>
                                </select>
                            </div>
                            <div class="nexa-task-field">
                                <label for="nexa-task-priority">Priority</label>
                                <div class="nexa-task-priority-control">
                                    <select id="nexa-task-priority" class="form-control" data-nexa-task-priority>
                                        <option value="">None</option>
                                        <option value="Low">Low</option>
                                        <option value="Medium">Medium</option>
                                        <option value="High">High</option>
                                    </select>
                                    <span class="nexa-task-priority-dot nexa-task-priority-dot--none" data-nexa-task-priority-preview aria-hidden="true"></span>
                                </div>
                            </div>
                        </div>

                        <div class="nexa-task-field">
                            <label>Assigned to</label>
                            <div data-nexa-task-assigned-user-host aria-label="Assigned to"><div class="nexa-note-editor-loading"><span class="fas fa-circle-notch fa-spin" aria-hidden="true"></span><span>Loading</span></div></div>
                        </div>

                        <div class="nexa-task-field">
                            <label>Task notes</label>
                            <div class="nexa-native-rich-editor" data-nexa-task-notes-host aria-label="Task notes"><div class="nexa-note-editor-loading"><span class="fas fa-circle-notch fa-spin" aria-hidden="true"></span><span>Loading editor</span></div></div>
                        </div>

                        <p class="nexa-note-error" data-nexa-task-error role="alert" hidden></p>
                        <footer>
                            <button type="button" class="btn btn-default" data-nexa-task-close>Cancel</button>
                            <button type="submit" class="btn btn-primary" data-nexa-save-task disabled><span class="fas fa-check" aria-hidden="true"></span><span>Create</span></button>
                        </footer>
                    </form>
                </section>`;

            document.body.append(overlay);
            this.taskDialog = overlay;
            this.taskDialogReturnFocus = this.element.querySelector('[data-nexa-contact-action="task"]');
            overlay.querySelectorAll('[data-nexa-task-close]').forEach(button => {
                button.addEventListener('click', () => this.closeTaskDialog());
            });
            overlay.addEventListener('mousedown', event => {
                if (event.target === overlay) this.closeTaskDialog();
            });
            overlay.addEventListener('keydown', event => this.handleTaskDialogKeys(event));
            overlay.querySelector('[data-nexa-task-form]').addEventListener('submit', event => {
                event.preventDefault();
                this.saveTaskDialog(event.currentTarget);
            });

            const reminderSelect = overlay.querySelector('[data-nexa-task-reminder]');
            const remindAtField = overlay.querySelector('[data-nexa-task-remind-at-field]');
            reminderSelect.addEventListener('change', () => {
                remindAtField.hidden = reminderSelect.value !== 'custom';
            });

            const prioritySelect = overlay.querySelector('[data-nexa-task-priority]');
            const priorityPreview = overlay.querySelector('[data-nexa-task-priority-preview]');
            prioritySelect.addEventListener('change', () => {
                priorityPreview.className = `nexa-task-priority-dot ${this.taskPriorityDotClass(prioritySelect.value)}`;
            });

            try {
                this.taskEditorModel = await this.getModelFactory().create('Task');
                if (!this.taskDialog?.isConnected) return;
                this.taskEditorModel.set({
                    assignedUserId: this.getUser().id,
                    assignedUserName: this.getUser().get('name'),
                });

                this.taskDateEndView = await this.createView('nexaTaskDateEnd', 'views/fields/datetime-optional', {
                    fullSelector: '[data-nexa-task-dateend-host]',
                    model: this.taskEditorModel,
                    name: 'dateEnd',
                    mode: 'edit',
                    params: {required: true},
                });
                this.taskNotesEditorView = await this.createView('nexaTaskNotesEditor', 'views/fields/wysiwyg', {
                    fullSelector: '[data-nexa-task-notes-host]',
                    model: this.taskEditorModel,
                    name: 'description',
                    mode: 'edit',
                    params: {height: 220, minHeight: 160},
                });

                if (!this.taskDialog?.isConnected) return;
                await Promise.all([
                    this.taskDateEndView.render(),
                    this.taskNotesEditorView.render(),
                ]);

                await this.ensureTenantOwnersLoaded();
                if (!this.taskDialog?.isConnected) return;
                const assignedUserHost = overlay.querySelector('[data-nexa-task-assigned-user-host]');
                this.taskAssigneePicker = this.mountAssigneePicker(assignedUserHost, {
                    userId: this.taskEditorModel.get('assignedUserId'),
                    userName: this.taskEditorModel.get('assignedUserName'),
                    onChange: (id, name) => {
                        this.taskEditorModel.set({assignedUserId: id, assignedUserName: name});
                    },
                });

                overlay.querySelector('[data-nexa-save-task]').disabled = false;
                window.setTimeout(() => overlay.querySelector('#nexa-task-name')?.focus(), 0);
            } catch (error) {
                const message = overlay.querySelector('[data-nexa-task-error]');
                message.textContent = 'The task form could not be loaded.';
                message.hidden = false;
            }
        }

        handleTaskDialogKeys(event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                this.closeTaskDialog();
                return;
            }
            if (event.key !== 'Tab') return;

            const controls = [...this.taskDialog.querySelectorAll('button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled])')];
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

        async saveTaskDialog(form = null) {
            form = form || this.taskDialog?.querySelector('[data-nexa-task-form]');
            if (!form || !this.taskEditorModel || this.taskSavePending) return;

            const error = form.querySelector('[data-nexa-task-error]');
            error.hidden = true;

            const name = String(form.querySelector('#nexa-task-name')?.value || '').trim();
            if (!name) {
                error.textContent = 'Enter a task name.';
                error.hidden = false;
                return;
            }
            if (!this.taskEditorModel.get('assignedUserId')) {
                error.textContent = 'Choose someone to assign this task to.';
                error.hidden = false;
                return;
            }

            this.taskDateEndView.fetchToModel();
            this.taskNotesEditorView.fetchToModel();

            const dateEnd = this.taskEditorModel.get('dateEnd');
            if (!dateEnd) {
                error.textContent = 'Select a due date and time.';
                error.hidden = false;
                return;
            }

            const reminderChoice = form.querySelector('[data-nexa-task-reminder]')?.value || 'none';
            let reminders = [];
            if (reminderChoice !== 'none') {
                const customValue = form.querySelector('[data-nexa-task-remind-at]')?.value;
                const {seconds, error: reminderError} = this.computeTaskReminderSeconds(reminderChoice, dateEnd, customValue);
                if (reminderError) {
                    error.textContent = reminderError;
                    error.hidden = false;
                    return;
                }
                reminders = [{type: 'Popup', seconds}, {type: 'Email', seconds}];
            }

            this.taskEditorModel.set({
                name,
                parentType: 'Contact',
                parentId: this.model.id,
                parentName: this.model.get('name'),
                taskType: form.querySelector('#nexa-task-type')?.value || 'To-Do',
                priority: form.querySelector('[data-nexa-task-priority]')?.value || '',
                reminders,
            });

            const submit = form.querySelector('[data-nexa-save-task]');
            this.taskSavePending = true;
            submit.disabled = true;
            submit.classList.add('is-loading');

            try {
                await this.taskEditorModel.save(null);
                const newTaskId = this.taskEditorModel.id;
                this.closeTaskDialog();
                Espo.Ui.success('Task created');
                this.model.trigger('update-related:tasks');
                this.model.trigger('update-related:activities');
                await this.loadContactTasks(this.element.querySelector('[data-nexa-contact-workspace]'), newTaskId);
            } catch (saveError) {
                error.textContent = 'The task could not be saved. Check your access and try again.';
                error.hidden = false;
                submit.disabled = false;
                submit.classList.remove('is-loading');
            } finally {
                this.taskSavePending = false;
            }
        }

        closeTaskDialog() {
            const returnFocus = this.taskDialogReturnFocus;
            if (this.getView('nexaTaskDateEnd')) this.clearView('nexaTaskDateEnd');
            if (this.getView('nexaTaskNotesEditor')) this.clearView('nexaTaskNotesEditor');
            this.taskAssigneePicker?.destroy();
            this.taskAssigneePicker = null;
            this.taskDialog?.remove();
            this.taskDialog = null;
            this.taskDialogReturnFocus = null;
            this.taskDateEndView = null;
            this.taskNotesEditorView = null;
            this.taskEditorModel = null;
            this.taskSavePending = false;
            returnFocus?.focus?.();
        }

        computeTaskReminderSeconds(choice, dateEndIso, customRemindAtIso) {
            if (!choice || choice === 'none') return {seconds: null, error: null};

            const presetSeconds = {'at-time': 0, '30m': 1800, '1h': 3600, '1d': 86400, '1w': 604800};
            if (choice !== 'custom') {
                return {seconds: presetSeconds[choice] ?? 0, error: null};
            }

            if (!customRemindAtIso) {
                return {seconds: null, error: 'Choose a reminder date and time.'};
            }

            const customDate = new Date(customRemindAtIso);
            const dueDate = new Date(dateEndIso.includes('T') ? dateEndIso : `${dateEndIso.replace(' ', 'T')}Z`);
            const seconds = Math.round((dueDate.getTime() - customDate.getTime()) / 1000);
            if (!Number.isFinite(seconds) || seconds < 0) {
                return {seconds: null, error: 'Choose a reminder time before the due date.'};
            }

            return {seconds, error: null};
        }

        taskReminderPresetFromSeconds(seconds) {
            if (seconds === null || seconds === undefined) return 'none';
            const map = {0: 'at-time', 1800: '30m', 3600: '1h', 86400: '1d', 604800: '1w'};
            return map[seconds] ?? 'custom';
        }

        taskPriorityDotClass(value) {
            const map = {
                Low: 'nexa-task-priority-dot--low',
                Medium: 'nexa-task-priority-dot--medium',
                High: 'nexa-task-priority-dot--high',
            };
            return map[value] || 'nexa-task-priority-dot--none';
        }

        taskOptionLabel(field, value) {
            if (value === null || value === undefined || value === '') return 'None';
            return this.getLanguage().translateOption(value, field, 'Task') || value;
        }

        taskPriorityDot(value) {
            const label = this.taskOptionLabel('priority', value);
            return `<span class="nexa-task-priority-dot ${this.taskPriorityDotClass(value)}" aria-hidden="true"></span>${this.escape(label)}`;
        }

        taskStatusBadge(value) {
            if (!value) return '<span class="nexa-record-empty">Not recorded</span>';

            const statusClasses = {
                'Not Started': 'not-started',
                Started: 'in-progress',
                Waiting: 'waiting',
                Completed: 'completed',
                Canceled: 'canceled',
                Deferred: 'deferred',
            };
            const label = this.taskOptionLabel('status', value);
            return `<span class="nexa-task-status nexa-task-status--${statusClasses[value] || 'other'}">${this.escape(label)}</span>`;
        }

        taskReminderSummary(task) {
            if (!Array.isArray(task.reminders) || !task.reminders.length) return 'No reminder';

            const seconds = task.reminders[0].seconds ?? 0;
            const labels = {
                0: 'At task due time',
                1800: '30 minutes before',
                3600: '1 hour before',
                86400: '1 day before',
                604800: '1 week before',
            };
            return labels[seconds] || `${Math.round(seconds / 60)} minutes before`;
        }

        taskIsOverdue(task) {
            if (!task.dateEnd || ['Completed', 'Canceled'].includes(task.status)) return false;
            const date = this.contactNoteDate(task.dateEnd);
            return date ? date.getTime() < Date.now() : false;
        }

        bindContactTasksWorkspace(shell) {
            this.contactTaskFilter = this.contactTaskFilter || {
                query: '', period: 'all', owner: 'all', statuses: new Set(), priorities: new Set(),
            };
            this.collapsedContactTaskIds = this.collapsedContactTaskIds || new Set();

            shell.querySelector('[data-nexa-create-task]')?.addEventListener('click', () => this.openTaskDialog());
            shell.querySelector('[data-nexa-tasks-search]')?.addEventListener('input', event => {
                this.contactTaskFilter.query = event.currentTarget.value.trim().toLowerCase();
                this.renderContactTasks(shell);
            });
            shell.querySelector('[data-nexa-tasks-filter-toggle]')?.addEventListener('click', event => {
                const filters = shell.querySelector('[data-nexa-tasks-filters]');
                filters.hidden = !filters.hidden;
                event.currentTarget.setAttribute('aria-expanded', String(!filters.hidden));
            });
            shell.querySelector('[data-nexa-task-period-toggle]')?.addEventListener('click', event => {
                event.stopPropagation();
                this.toggleTaskFilterMenu(shell, 'period');
            });
            shell.querySelector('[data-nexa-task-owner-toggle]')?.addEventListener('click', event => {
                event.stopPropagation();
                this.toggleTaskFilterMenu(shell, 'owner');
            });
            shell.querySelectorAll('[data-nexa-task-period]').forEach(button => {
                button.addEventListener('click', () => {
                    this.contactTaskFilter.period = button.dataset.nexaTaskPeriod;
                    shell.querySelector('[data-nexa-task-period-label]').textContent = button.textContent.trim();
                    this.closeTaskFilterMenus(shell);
                    this.renderContactTasks(shell);
                });
            });
            shell.querySelector('[data-nexa-task-owner-search]')?.addEventListener('input', event => {
                const term = event.currentTarget.value.trim().toLowerCase();
                shell.querySelectorAll('[data-nexa-task-owner-option]').forEach(button => {
                    button.hidden = term && !button.dataset.nexaOwnerSearch.includes(term);
                });
            });
            shell.querySelector('[data-nexa-task-status-toggle]')?.addEventListener('click', event => {
                event.stopPropagation();
                this.toggleTaskFilterMenu(shell, 'status');
            });
            shell.querySelector('[data-nexa-task-priority-toggle]')?.addEventListener('click', event => {
                event.stopPropagation();
                this.toggleTaskFilterMenu(shell, 'priority');
            });
            shell.querySelectorAll('[data-nexa-task-status-option]').forEach(checkbox => {
                checkbox.addEventListener('change', () => {
                    if (checkbox.checked) this.contactTaskFilter.statuses.add(checkbox.value);
                    else this.contactTaskFilter.statuses.delete(checkbox.value);
                    this.updateTaskFilterLabel(shell, 'status', this.contactTaskFilter.statuses.size);
                    this.renderContactTasks(shell);
                });
            });
            shell.querySelectorAll('[data-nexa-task-priority-option]').forEach(checkbox => {
                checkbox.addEventListener('change', () => {
                    if (checkbox.checked) this.contactTaskFilter.priorities.add(checkbox.value);
                    else this.contactTaskFilter.priorities.delete(checkbox.value);
                    this.updateTaskFilterLabel(shell, 'priority', this.contactTaskFilter.priorities.size);
                    this.renderContactTasks(shell);
                });
            });
            shell.querySelector('[data-nexa-collapse-tasks]')?.addEventListener('click', () => {
                const visible = this.filteredContactTasks();
                const allCollapsed = visible.length > 0 && visible.every(task => this.collapsedContactTaskIds.has(task.id));
                if (allCollapsed) {
                    visible.forEach(task => this.collapsedContactTaskIds.delete(task.id));
                } else {
                    visible.forEach(task => this.collapsedContactTaskIds.add(task.id));
                }
                this.renderContactTasks(shell);
            });

            if (this.tasksFilterDocumentHandler) document.removeEventListener('click', this.tasksFilterDocumentHandler);
            this.tasksFilterDocumentHandler = event => {
                if (!event.target.closest('[data-nexa-task-period-filter], [data-nexa-task-owner-filter], [data-nexa-task-status-filter], [data-nexa-task-priority-filter]')) {
                    this.closeTaskFilterMenus(shell);
                }
            };
            document.addEventListener('click', this.tasksFilterDocumentHandler);
        }

        taskFilterTypes() {
            return ['period', 'owner', 'status', 'priority'];
        }

        toggleTaskFilterMenu(shell, type) {
            const ownMenu = shell.querySelector(`[data-nexa-task-${type}-menu]`);
            const ownToggle = shell.querySelector(`[data-nexa-task-${type}-toggle]`);
            if (!ownMenu || !ownToggle) return;
            const opening = ownMenu.hidden;
            this.taskFilterTypes().forEach(otherType => {
                if (otherType === type) return;
                shell.querySelector(`[data-nexa-task-${otherType}-menu]`)?.setAttribute('hidden', '');
                shell.querySelector(`[data-nexa-task-${otherType}-toggle]`)?.setAttribute('aria-expanded', 'false');
            });
            ownMenu.hidden = !opening;
            ownToggle.setAttribute('aria-expanded', String(opening));
            if (opening && type === 'owner') window.setTimeout(() => ownMenu.querySelector('input')?.focus(), 0);
        }

        closeTaskFilterMenus(shell) {
            this.taskFilterTypes().forEach(type => {
                shell.querySelector(`[data-nexa-task-${type}-menu]`)?.setAttribute('hidden', '');
                shell.querySelector(`[data-nexa-task-${type}-toggle]`)?.setAttribute('aria-expanded', 'false');
            });
        }

        updateTaskFilterLabel(shell, type, count) {
            const labels = {status: 'Task stage', priority: 'Priority'};
            const label = shell.querySelector(`[data-nexa-task-${type}-label]`);
            if (label) label.textContent = count > 0 ? `${labels[type]} (${count})` : labels[type];
        }

        async loadContactTaskOwners(workspace) {
            const taskAssignees = (this.contactTaskRecords || []).map(task => ({
                id: task.assignedUserId,
                name: task.assignedUserName || 'Removed owner',
                isActive: true,
            })).filter(owner => owner.id);

            await this.ensureTenantOwnersLoaded();

            const owners = new Map();
            [...this.contactNoteTenantOwners, ...taskAssignees].forEach(owner => {
                if (!owners.has(owner.id) || owner.isActive === false) owners.set(owner.id, owner);
            });
            this.contactTaskOwners = [...owners.values()].sort((a, b) => a.name.localeCompare(b.name));
            const knownIds = new Set(this.contactNoteTenantOwners.map(owner => owner.id));
            this.deactivatedContactOwnerIds = new Set([
                ...(this.deactivatedContactOwnerIds || []),
                ...this.contactTaskOwners
                    .filter(owner => owner.isActive === false || !knownIds.has(owner.id))
                    .map(owner => owner.id),
            ]);
            this.renderContactTaskOwnerOptions(workspace);
        }

        renderContactTaskOwnerOptions(workspace) {
            const container = workspace?.querySelector('[data-nexa-task-owner-options]');
            if (!container) return;
            const owners = this.contactTaskOwners || [];
            container.innerHTML = `
                <button type="button" data-nexa-task-owner-option="all" data-nexa-owner-search="all owners">All owners</button>
                <button type="button" data-nexa-task-owner-option="me" data-nexa-owner-search="me my activity">Me</button>
                <button type="button" data-nexa-task-owner-option="deactivated" data-nexa-owner-search="deactivated removed owners">Deactivated and removed owners</button>
                ${owners.length ? '<p>Owners</p>' : ''}
                ${owners.map(owner => `<button type="button" data-nexa-task-owner-option="${this.escape(owner.id)}" data-nexa-owner-search="${this.escape(owner.name.toLowerCase())}">${this.escape(owner.name)}${owner.isActive === false ? ' (deactivated)' : ''}</button>`).join('')}`;
            container.querySelectorAll('[data-nexa-task-owner-option]').forEach(button => {
                button.addEventListener('click', () => {
                    const value = button.dataset.nexaTaskOwnerOption;
                    this.contactTaskFilter.owner = value;
                    const labels = {
                        all: 'Activity assigned to',
                        me: 'Me',
                        deactivated: 'Deactivated and removed owners',
                    };
                    workspace.querySelector('[data-nexa-task-owner-label]').textContent = labels[value] || button.textContent.trim();
                    const search = workspace.querySelector('[data-nexa-task-owner-search]');
                    search.value = '';
                    container.querySelectorAll('[data-nexa-task-owner-option]').forEach(option => option.hidden = false);
                    this.closeTaskFilterMenus(workspace);
                    this.renderContactTasks(workspace);
                });
            });
        }

        async loadContactTasks(shell = null, newestId = null) {
            const workspace = shell || this.element.querySelector('[data-nexa-contact-workspace]');
            const list = workspace?.querySelector('[data-nexa-task-list]');
            const count = workspace?.querySelector('[data-nexa-task-count]');
            if (!list || !count) return;

            try {
                const payload = await Espo.Ajax.getRequest('Task', {
                    where: [
                        {type: 'equals', attribute: 'parentType', value: 'Contact'},
                        {type: 'equals', attribute: 'parentId', value: this.model.id},
                    ],
                    select: 'id,name,status,priority,taskType,dateEnd,assignedUserId,assignedUserName,description,createdAt,reminders,isPinned',
                    maxSize: 200,
                    orderBy: 'dateEnd',
                    order: 'asc',
                });
                this.contactTaskRecords = Array.isArray(payload?.list) ? payload.list : [];
                this.knownContactTaskIds = this.knownContactTaskIds || new Set();
                this.contactTaskRecords.forEach(task => {
                    if (this.knownContactTaskIds.has(task.id)) return;
                    this.knownContactTaskIds.add(task.id);
                    this.collapsedContactTaskIds?.add(task.id);
                });
                await Promise.all(this.contactTaskRecords.map(async task => {
                    const model = await this.getModelFactory().create('Task');
                    model.set(task);
                    model.id = task.id;
                    const canEditModel = this.getAcl().checkModel(model, 'edit') === true;
                    task._editable = {
                        name: canEditModel && !!this.getAcl().checkField('Task', 'name', 'edit'),
                        description: canEditModel && !!this.getAcl().checkField('Task', 'description', 'edit'),
                        status: canEditModel && !!this.getAcl().checkField('Task', 'status', 'edit'),
                        taskType: canEditModel && !!this.getAcl().checkField('Task', 'taskType', 'edit'),
                        priority: canEditModel && !!this.getAcl().checkField('Task', 'priority', 'edit'),
                        assignedUser: canEditModel && !!this.getAcl().checkField('Task', 'assignedUser', 'edit'),
                        dueDateReminder: canEditModel && !!this.getAcl().checkField('Task', 'dateEnd', 'edit'),
                    };
                    task.canPin = canEditModel;
                    task.canDelete = this.getAcl().checkModel(model, 'delete') === true;
                }));
                this.renderContactTasks(workspace, newestId);
                this.renderContactActivities(workspace);
                this.renderContactOverviewInsights(workspace);
                this.loadContactTaskCommentCounts(workspace);
                this.loadContactTaskOwners(workspace);
            } catch (error) {
                list.innerHTML = `<div class="nexa-note-empty is-error"><span class="fas fa-exclamation-circle" aria-hidden="true"></span><div><strong>Tasks unavailable</strong><p>Refresh the page to try loading them again.</p></div></div>`;
            }
        }

        async loadContactTaskCommentCounts(workspace) {
            const ids = (this.contactTaskRecords || []).map(task => task.id);
            this.contactTaskCommentCounts = new Map();
            if (!ids.length) {
                this.renderContactTasks(workspace);
                this.renderContactActivities(workspace);
                return;
            }
            try {
                const payload = await Espo.Ajax.getRequest('Note', {
                    where: [
                        {type: 'equals', attribute: 'parentType', value: 'Task'},
                        {type: 'in', attribute: 'parentId', value: ids},
                        {type: 'equals', attribute: 'type', value: 'Post'},
                    ],
                    select: 'id,parentId',
                    maxSize: 200,
                });
                (payload?.list || []).forEach(note => {
                    const current = this.contactTaskCommentCounts.get(note.parentId) || 0;
                    this.contactTaskCommentCounts.set(note.parentId, current + 1);
                });
            } catch (error) {
                // Comment counts are a display enhancement only; failures are non-blocking.
            }
            this.renderContactTasks(workspace);
            this.renderContactActivities(workspace);
        }

        filteredContactTasks() {
            const filter = this.contactTaskFilter || {query: '', period: 'all', owner: 'all', statuses: new Set(), priorities: new Set()};
            const currentUserId = this.getUser()?.id || this.getUser()?.get?.('id');
            return (this.contactTaskRecords || []).filter(task => {
                const matchesQuery = !filter.query ||
                    `${task.name || ''} ${task.assignedUserName || ''}`.toLowerCase().includes(filter.query);
                const ownerId = task.assignedUserId || '';
                const matchesOwner = !filter.owner || filter.owner === 'all' ||
                    (filter.owner === 'me' && ownerId === currentUserId) ||
                    (filter.owner === 'deactivated' && this.deactivatedContactOwnerIds?.has(ownerId)) ||
                    ownerId === filter.owner;
                const matchesPeriod = this.contactNoteMatchesPeriod(task.dateEnd || task.createdAt, filter.period);
                const matchesStatus = !filter.statuses?.size || filter.statuses.has(task.status);
                const matchesPriority = !filter.priorities?.size || filter.priorities.has(task.priority || 'none');
                return matchesQuery && matchesOwner && matchesPeriod && matchesStatus && matchesPriority;
            });
        }

        renderContactTasks(workspace = null, newestId = null) {
            workspace = workspace || this.element.querySelector('[data-nexa-contact-workspace]');
            const list = workspace?.querySelector('[data-nexa-task-list]');
            const count = workspace?.querySelector('[data-nexa-task-count]');
            if (!list || !count) return;
            this.cancelTaskFieldEdit();

            const tasks = this.filteredContactTasks();
            const groups = new Map();
            const pinned = tasks.filter(task => task.isPinned);
            const chronological = tasks.filter(task => !task.isPinned);
            if (pinned.length) groups.set('pinned', {label: 'Pinned', tasks: pinned, pinned: true});
            chronological.forEach(task => {
                const date = this.contactNoteDate(task.dateEnd);
                const key = date ? `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}` : 'unknown';
                const label = date
                    ? new Intl.DateTimeFormat(undefined, {month: 'long', year: 'numeric'}).format(date)
                    : 'No due date';
                if (!groups.has(key)) groups.set(key, {label, tasks: []});
                groups.get(key).tasks.push(task);
            });

            count.textContent = `${tasks.length} ${tasks.length === 1 ? 'task' : 'tasks'}`;
            list.innerHTML = tasks.length
                ? [...groups.values()].map(group => `<section class="nexa-note-month${group.pinned ? ' is-pinned-group' : ''}"><h4>${group.pinned ? '<span class="fas fa-thumbtack" aria-hidden="true"></span>' : ''}${this.escape(group.label)}</h4>${group.tasks.map(task => this.contactTaskCard(task, task.id === newestId)).join('')}</section>`).join('')
                : `<div class="nexa-note-empty"><span class="far fa-check-square" aria-hidden="true"></span><div><strong>No matching tasks</strong><p>Try another search or create a new task.</p></div></div>`;

            const collapse = workspace.querySelector('[data-nexa-collapse-tasks]');
            const allCollapsed = tasks.length > 0 && tasks.every(task => this.collapsedContactTaskIds?.has(task.id));
            if (collapse) collapse.firstChild.textContent = allCollapsed ? 'Expand all ' : 'Collapse all ';
            this.bindContactTaskList(list);
        }

        contactTaskCard(task, isNewest = false, context = 'tasks') {
            const collapsed = this.collapsedContactTaskIds?.has(task.id);
            const commentCount = this.contactTaskCommentCounts?.get(task.id) || 0;
            const comments = this.contactTaskComments?.get(task.id) || [];
            const commentsVisible = this.taskCommentsVisibleIds?.has(task.id) === true;
            const overdue = this.taskIsOverdue(task);
            const isCompleted = task.status === 'Completed';
            const dueLabel = task.dateEnd ? this.getDateTime().toDisplay(task.dateEnd) : 'No due date';
            const createdLabel = task.createdAt ? this.getDateTime().toDisplay(task.createdAt) : '';
            const nameText = `<span class="nexa-task-name-text${isCompleted ? ' is-completed' : ''}">${this.escape(task.name || 'Untitled task')}</span>`;
            const editorHost = `${context}-${task.id}`;
            const canComment = this.getAcl().checkScope('Note', 'create') === true;
            const canDelete = task.canDelete === true;
            const canPin = task.canPin === true;
            const deleteHelp = "You don't have permission to delete this task. Ask your admin to grant permission.";

            return `<article class="nexa-note-card${isNewest ? ' is-new' : ''}${collapsed ? ' is-collapsed' : ''}${task.isPinned ? ' is-pinned' : ''}" data-nexa-task-id="${this.escape(task.id)}" data-nexa-task-context="${this.escape(context)}">
                <header>
                    <button class="nexa-note-toggle" type="button" data-nexa-task-toggle aria-expanded="${!collapsed}"><span class="fas fa-chevron-${collapsed ? 'right' : 'down'}" aria-hidden="true"></span>${task.isPinned ? '<span class="fas fa-thumbtack nexa-note-pinned-icon" aria-label="Pinned task"></span>' : ''}<span class="nexa-note-kind">Task</span><span>assigned to <strong>${this.escape(task.assignedUserName || 'Unassigned')}</strong></span>${overdue ? '<span class="nexa-note-overdue-badge" title="Overdue"><span class="fas fa-exclamation-circle" aria-hidden="true"></span></span>' : ''}${commentCount ? `<span class="nexa-note-comment-count"><span class="far fa-comment" aria-hidden="true"></span>${commentCount}</span>` : ''}</button>
                    <div class="nexa-note-header-meta"><div class="nexa-note-actions" data-nexa-task-actions${collapsed ? ' hidden' : ''}><button type="button" class="nexa-note-actions-toggle" data-nexa-task-actions-toggle aria-expanded="false">Actions <span class="fas fa-caret-down" aria-hidden="true"></span></button><div class="nexa-note-actions-menu" data-nexa-task-actions-menu hidden><button type="button" data-nexa-task-pin${canPin ? '' : ' disabled aria-disabled="true"'}><span class="fas fa-thumbtack" aria-hidden="true"></span>${task.isPinned ? 'Unpin' : 'Pin'}</button>${canDelete ? '<button type="button" class="is-danger" data-nexa-task-delete><span class="far fa-trash-alt" aria-hidden="true"></span>Delete</button>' : `<span class="nexa-note-action-disabled" data-tooltip="${this.escape(deleteHelp)}" tabindex="0"><button type="button" class="is-danger" disabled aria-disabled="true"><span class="far fa-trash-alt" aria-hidden="true"></span>Delete</button></span>`}</div></div><time>${this.escape(createdLabel)}</time></div>
                </header>
                <p class="nexa-note-preview"${collapsed ? '' : ' hidden'}>${this.taskCompleteToggleButton(task)}${nameText}</p>
                <div class="nexa-note-details" data-nexa-task-details${collapsed ? ' hidden' : ''}>
                    ${this.taskTitleRow(task, nameText)}
                    <dl class="nexa-activity-audit-details">
                        ${this.taskInlineFactRow(task, 'dueDateReminder', 'Due date & reminder', `${this.escape(dueLabel)} &middot; ${this.escape(this.taskReminderSummary(task))}`)}
                    </dl>
                    <div class="nexa-task-fact-line">
                        ${this.taskInlineFactItem(task, 'status', 'Task stage', this.taskStatusBadge(task.status))}
                        ${this.taskInlineFactItem(task, 'taskType', 'Task type', this.escape(task.taskType || 'To-Do'))}
                        ${this.taskInlineFactItem(task, 'priority', 'Priority', this.taskPriorityDot(task.priority))}
                        ${this.taskInlineFactItem(task, 'assignedUser', 'Assigned to', this.escape(task.assignedUserName || 'Unassigned'))}
                    </div>
                    ${this.taskNotesSection(task)}
                    <footer>
                        ${canComment ? '<button type="button" data-nexa-comment-toggle><span class="far fa-comment" aria-hidden="true"></span><span>Add comment</span></button>' : ''}
                        ${commentCount ? `<button type="button" class="nexa-task-reply-toggle" data-nexa-task-comments-visibility-toggle>${commentsVisible ? 'Hide comments' : `Show comments (${commentCount})`}</button>` : '<span>0 comments</span>'}
                    </footer>
                    <div class="nexa-note-comments"${commentsVisible ? '' : ' hidden'}>${comments.map(comment => this.contactTaskComment(comment, context)).join('')}</div>
                    <form class="nexa-note-comment-form" data-nexa-comment-form hidden>
                        <div class="nexa-native-rich-editor nexa-comment-editor" data-nexa-comment-editor-host="${this.escape(editorHost)}" aria-label="Comment"><div class="nexa-note-editor-loading"><span class="fas fa-circle-notch fa-spin" aria-hidden="true"></span><span>Loading editor</span></div></div>
                        <div><button type="button" class="btn btn-default btn-xs" data-nexa-comment-cancel>Cancel</button><button type="submit" class="btn btn-primary btn-xs">Comment</button></div>
                        <p role="alert" data-nexa-comment-error hidden></p>
                    </form>
                </div>
            </article>`;
        }

        taskCompleteToggleButton(task) {
            const isCompleted = task.status === 'Completed';
            const icon = '<span class="fas fa-check-square" aria-hidden="true"></span>';
            if (!task._editable?.status) {
                return `<span class="nexa-task-complete-toggle${isCompleted ? ' is-completed' : ''}" aria-hidden="true">${icon}</span>`;
            }
            return `<button type="button" class="nexa-task-complete-toggle${isCompleted ? ' is-completed' : ''}" data-nexa-task-complete-toggle aria-label="${isCompleted ? 'Mark as not started' : 'Mark as completed'}" aria-pressed="${isCompleted}">${icon}</button>`;
        }

        taskTitleRow(task, nameText) {
            const toggle = this.taskCompleteToggleButton(task);
            if (!task._editable?.name) {
                return `<p class="nexa-task-title">${toggle}${nameText}</p>`;
            }

            return `<div class="nexa-task-title nexa-inline-detail nexa-inline-detail--title" data-nexa-task-inline-field data-field="name">
                ${toggle}
                <span class="nexa-inline-detail-display" data-nexa-inline-display>${nameText}</span>
                <button type="button" class="nexa-inline-detail-trigger" data-nexa-task-inline-trigger aria-label="Edit task name"><span class="fas fa-pencil-alt" aria-hidden="true"></span></button>
            </div>`;
        }

        taskNotesSection(task) {
            const bodyHtml = task.description ? this.formatNoteContent(task.description) : '<span class="nexa-record-empty">No notes added</span>';
            if (!task._editable?.description) {
                return `<div class="nexa-task-notes-section"><h5 class="nexa-task-notes-heading">Task Notes</h5><div class="nexa-note-body">${bodyHtml}</div></div>`;
            }

            return `<div class="nexa-task-notes-section nexa-inline-detail nexa-inline-detail--notes" data-nexa-task-inline-field data-field="description">
                <div class="nexa-task-notes-heading-row"><h5 class="nexa-task-notes-heading">Task Notes</h5><button type="button" class="nexa-inline-detail-trigger" data-nexa-task-inline-trigger aria-label="Edit task notes"><span class="fas fa-pencil-alt" aria-hidden="true"></span></button></div>
                <div class="nexa-note-body" data-nexa-inline-display>${bodyHtml}</div>
            </div>`;
        }

        taskInlineFactItem(task, field, label, displayHtml) {
            if (!task._editable?.[field]) {
                return `<span class="nexa-task-fact-item"><span class="nexa-task-fact-label">${this.escape(label)}</span><span class="nexa-task-fact-value-row"><strong>${displayHtml}</strong></span></span>`;
            }

            return `<span class="nexa-task-fact-item nexa-inline-detail nexa-inline-detail--compact" data-nexa-task-inline-field data-field="${this.escape(field)}">
                <span class="nexa-task-fact-label">${this.escape(label)}</span>
                <span class="nexa-task-fact-value-row">
                    <strong class="nexa-inline-detail-display" data-nexa-inline-display>${displayHtml}</strong>
                    <button type="button" class="nexa-inline-detail-trigger" data-nexa-task-inline-trigger aria-label="Edit ${this.escape(label)}"><span class="fas fa-pencil-alt" aria-hidden="true"></span></button>
                </span>
            </span>`;
        }

        taskInlineFactRow(task, field, label, displayHtml) {
            if (!task._editable?.[field]) {
                return `<div><dt>${this.escape(label)}</dt><dd>${displayHtml}</dd></div>`;
            }

            return `<div class="nexa-inline-detail nexa-inline-detail--compact" data-nexa-task-inline-field data-field="${this.escape(field)}">
                <dt>${this.escape(label)}</dt>
                <dd><span class="nexa-inline-detail-display" data-nexa-inline-display>${displayHtml}</span><button type="button" class="nexa-inline-detail-trigger" data-nexa-task-inline-trigger aria-label="Edit ${this.escape(label)}"><span class="fas fa-pencil-alt" aria-hidden="true"></span></button></dd>
            </div>`;
        }

        contactTaskComment(comment, context) {
            const author = comment.createdByName || 'Team member';
            const createdAt = comment.createdAt ? this.getDateTime().toDisplay(comment.createdAt) : '';
            const replies = comment.replies || [];
            const repliesVisible = this.taskRepliesVisibleIds?.has(comment.id) === true;
            const replyEditorHost = `${context}-reply-${comment.id}`;
            const canComment = this.getAcl().checkScope('Note', 'create') === true;

            return `<div class="nexa-note-comment" data-nexa-task-comment-id="${this.escape(comment.id)}">
                <div><strong>${this.escape(author)}</strong><time>${this.escape(createdAt)}</time></div>
                <p>${this.formatNoteContent(comment.content)}</p>
                <div class="nexa-task-comment-actions">
                    ${canComment ? '<button type="button" class="nexa-task-reply-toggle" data-nexa-task-reply-toggle>Reply</button>' : ''}
                    ${replies.length ? `<button type="button" class="nexa-task-reply-toggle" data-nexa-task-replies-visibility-toggle>${repliesVisible ? 'Hide replies' : `Show replies (${replies.length})`}</button>` : ''}
                </div>
                ${replies.length ? `<div class="nexa-task-comment-replies"${repliesVisible ? '' : ' hidden'}>${replies.map(reply => this.contactTaskReply(reply)).join('')}</div>` : ''}
                <form class="nexa-note-comment-form nexa-task-reply-form" data-nexa-reply-form hidden>
                    <div class="nexa-native-rich-editor nexa-comment-editor" data-nexa-comment-editor-host="${this.escape(replyEditorHost)}" aria-label="Reply"><div class="nexa-note-editor-loading"><span class="fas fa-circle-notch fa-spin" aria-hidden="true"></span><span>Loading editor</span></div></div>
                    <div><button type="button" class="btn btn-default btn-xs" data-nexa-reply-cancel>Cancel</button><button type="submit" class="btn btn-primary btn-xs">Reply</button></div>
                    <p role="alert" data-nexa-reply-error hidden></p>
                </form>
            </div>`;
        }

        contactTaskReply(reply) {
            const author = reply.createdByName || 'Team member';
            const createdAt = reply.createdAt ? this.getDateTime().toDisplay(reply.createdAt) : '';
            return `<div class="nexa-note-comment nexa-task-comment-reply"><div><strong>${this.escape(author)}</strong><time>${this.escape(createdAt)}</time></div><p>${this.formatNoteContent(reply.content)}</p></div>`;
        }

        async loadTaskComments(taskId, {force = false} = {}) {
            this.contactTaskComments = this.contactTaskComments || new Map();
            if (!force && this.contactTaskComments.has(taskId)) return;

            try {
                const payload = await Espo.Ajax.getRequest('Note', {
                    where: [
                        {type: 'equals', attribute: 'parentType', value: 'Task'},
                        {type: 'equals', attribute: 'parentId', value: taskId},
                        {type: 'equals', attribute: 'type', value: 'Post'},
                    ],
                    select: 'id,post,createdAt,createdById,createdByName',
                    orderBy: 'createdAt',
                    order: 'asc',
                    maxSize: 200,
                });
                const records = payload?.list || [];
                const topLevel = [];
                const repliesByParent = new Map();

                records.forEach(record => {
                    const post = String(record.post || '');
                    const replyMatch = post.match(/^<!-- nexa-task-reply:([A-Za-z0-9_-]+) -->\s*\n?/);
                    if (replyMatch) {
                        const list = repliesByParent.get(replyMatch[1]) || [];
                        list.push({...record, content: post.replace(replyMatch[0], '')});
                        repliesByParent.set(replyMatch[1], list);
                        return;
                    }
                    topLevel.push({...record, content: post});
                });
                topLevel.forEach(comment => {
                    comment.replies = repliesByParent.get(comment.id) || [];
                });

                this.contactTaskComments.set(taskId, topLevel);
                this.contactTaskCommentCounts = this.contactTaskCommentCounts || new Map();
                this.contactTaskCommentCounts.set(taskId, records.length);
            } catch (error) {
                this.contactTaskComments.set(taskId, []);
            }
        }

        async createTaskStreamNote(taskId, post) {
            const note = await this.getModelFactory().create('Note');
            note.set({
                type: 'Post',
                post,
                parentType: 'Task',
                parentId: taskId,
            });
            await note.save(null);
            return note;
        }

        async saveTaskComment(taskId, form) {
            if (form.dataset.saving === 'true') return;
            const context = form.closest('[data-nexa-task-context]')?.dataset.nexaTaskContext || 'tasks';
            const editor = this.contactCommentEditors?.get(`${context}-${taskId}`);
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
                const note = await this.createTaskStreamNote(taskId, content);
                this.contactTaskComments = this.contactTaskComments || new Map();
                const comments = this.contactTaskComments.get(taskId) || [];
                comments.push({
                    id: note.id,
                    content,
                    replies: [],
                    createdAt: note.get('createdAt') || new Date().toISOString(),
                    createdById: this.getUser().id,
                    createdByName: this.getUser().get('name'),
                });
                this.contactTaskComments.set(taskId, comments);
                this.contactTaskCommentCounts = this.contactTaskCommentCounts || new Map();
                this.contactTaskCommentCounts.set(taskId, comments.length);
                this.clearContactCommentEditor(taskId, context);
                form.hidden = true;
                this.taskCommentsVisibleIds = this.taskCommentsVisibleIds || new Set();
                this.taskCommentsVisibleIds.add(taskId);
                this.renderContactTasks();
                this.renderContactActivities();
            } catch (saveError) {
                error.textContent = 'The comment could not be saved.';
                error.hidden = false;
                form.dataset.saving = 'false';
                form.querySelector('button[type="submit"]').disabled = false;
            }
        }

        async saveTaskReply(taskId, commentId, form) {
            if (form.dataset.saving === 'true') return;
            const context = form.closest('[data-nexa-task-context]')?.dataset.nexaTaskContext || 'tasks';
            const editor = this.contactCommentEditors?.get(`${context}-reply-${commentId}`);
            if (!editor) return;
            editor.view.fetchToModel();
            const content = String(editor.model.get('post') || '').trim();
            const error = form.querySelector('[data-nexa-reply-error]');
            if (this.richTextIsEmpty(content)) {
                error.textContent = 'Enter a reply before saving.';
                error.hidden = false;
                return;
            }

            form.dataset.saving = 'true';
            form.querySelector('button[type="submit"]').disabled = true;
            error.hidden = true;
            try {
                await this.createTaskStreamNote(taskId, `<!-- nexa-task-reply:${commentId} -->\n${content}`);
                this.clearContactCommentEditor(`reply-${commentId}`, context);
                form.hidden = true;
                await this.loadTaskComments(taskId, {force: true});
                this.taskCommentsVisibleIds = this.taskCommentsVisibleIds || new Set();
                this.taskCommentsVisibleIds.add(taskId);
                this.taskRepliesVisibleIds = this.taskRepliesVisibleIds || new Set();
                this.taskRepliesVisibleIds.add(commentId);
                this.renderContactTasks();
                this.renderContactActivities();
            } catch (saveError) {
                error.textContent = 'The reply could not be saved.';
                error.hidden = false;
                form.dataset.saving = 'false';
                form.querySelector('button[type="submit"]').disabled = false;
            }
        }

        async toggleTaskCompletion(taskId) {
            const task = (this.contactTaskRecords || []).find(item => item.id === taskId);
            if (!task || task._togglingCompletion || !task._editable?.status) return;

            const newStatus = task.status === 'Completed' ? 'Not Started' : 'Completed';
            task._togglingCompletion = true;
            try {
                const model = await this.getModelFactory().create('Task');
                model.id = taskId;
                await model.save({status: newStatus}, {patch: true});
                task.status = newStatus;
                Espo.Ui.success(newStatus === 'Completed' ? 'Task completed' : 'Task reopened');
                this.renderContactTasks();
                this.renderContactActivities();
            } catch (error) {
                Espo.Ui.error(this.translate('Error occurred'));
            } finally {
                task._togglingCompletion = false;
            }
        }

        bindContactTaskList(list, context = 'tasks') {
            list.querySelectorAll('[data-nexa-task-toggle]').forEach(button => {
                button.addEventListener('click', () => {
                    const card = button.closest('[data-nexa-task-id]');
                    const id = card.dataset.nexaTaskId;
                    const expanding = this.collapsedContactTaskIds.has(id);
                    if (expanding) this.collapsedContactTaskIds.delete(id);
                    else this.collapsedContactTaskIds.add(id);
                    if (expanding && !this.contactTaskComments?.has(id)) {
                        this.loadTaskComments(id).then(() => {
                            this.renderContactTasks();
                            this.renderContactActivities();
                        });
                    }
                    this.renderContactTasks();
                    this.renderContactActivities();
                });
            });
            list.querySelectorAll('[data-nexa-comment-toggle]').forEach(button => {
                button.addEventListener('click', () => {
                    const card = button.closest('[data-nexa-task-id]');
                    const form = card.querySelector('[data-nexa-comment-form]');
                    form.hidden = false;
                    this.mountContactCommentEditor(card.dataset.nexaTaskId, form, context);
                });
            });
            list.querySelectorAll('[data-nexa-comment-cancel]').forEach(button => {
                button.addEventListener('click', () => {
                    const form = button.closest('[data-nexa-comment-form]');
                    this.clearContactCommentEditor(form.closest('[data-nexa-task-id]').dataset.nexaTaskId, context);
                    form.hidden = true;
                });
            });
            list.querySelectorAll('[data-nexa-comment-form]').forEach(form => {
                form.addEventListener('submit', event => {
                    event.preventDefault();
                    this.saveTaskComment(form.closest('[data-nexa-task-id]').dataset.nexaTaskId, form);
                });
            });
            list.querySelectorAll('[data-nexa-task-inline-trigger]').forEach(button => {
                button.addEventListener('click', () => {
                    const row = button.closest('[data-nexa-task-inline-field]');
                    const taskId = row.closest('[data-nexa-task-id]').dataset.nexaTaskId;
                    const field = row.dataset.field;
                    this.startTaskFieldEdit(taskId, field, row);
                });
            });
            list.querySelectorAll('[data-nexa-task-complete-toggle]').forEach(button => {
                button.addEventListener('click', event => {
                    event.stopPropagation();
                    const taskId = button.closest('[data-nexa-task-id]').dataset.nexaTaskId;
                    this.toggleTaskCompletion(taskId);
                });
            });
            list.querySelectorAll('[data-nexa-task-reply-toggle]').forEach(button => {
                button.addEventListener('click', () => {
                    const commentEl = button.closest('[data-nexa-task-comment-id]');
                    const commentId = commentEl.dataset.nexaTaskCommentId;
                    const form = commentEl.querySelector('[data-nexa-reply-form]');
                    form.hidden = false;
                    this.mountContactCommentEditor(`reply-${commentId}`, form, context);
                });
            });
            list.querySelectorAll('[data-nexa-reply-cancel]').forEach(button => {
                button.addEventListener('click', () => {
                    const form = button.closest('[data-nexa-reply-form]');
                    const commentId = form.closest('[data-nexa-task-comment-id]').dataset.nexaTaskCommentId;
                    this.clearContactCommentEditor(`reply-${commentId}`, context);
                    form.hidden = true;
                });
            });
            list.querySelectorAll('[data-nexa-reply-form]').forEach(form => {
                form.addEventListener('submit', event => {
                    event.preventDefault();
                    const taskId = form.closest('[data-nexa-task-id]').dataset.nexaTaskId;
                    const commentId = form.closest('[data-nexa-task-comment-id]').dataset.nexaTaskCommentId;
                    this.saveTaskReply(taskId, commentId, form);
                });
            });
            list.querySelectorAll('[data-nexa-task-comments-visibility-toggle]').forEach(button => {
                button.addEventListener('click', () => {
                    const taskId = button.closest('[data-nexa-task-id]').dataset.nexaTaskId;
                    this.taskCommentsVisibleIds = this.taskCommentsVisibleIds || new Set();
                    if (this.taskCommentsVisibleIds.has(taskId)) this.taskCommentsVisibleIds.delete(taskId);
                    else this.taskCommentsVisibleIds.add(taskId);
                    this.renderContactTasks();
                    this.renderContactActivities();
                });
            });
            list.querySelectorAll('[data-nexa-task-replies-visibility-toggle]').forEach(button => {
                button.addEventListener('click', () => {
                    const commentId = button.closest('[data-nexa-task-comment-id]').dataset.nexaTaskCommentId;
                    this.taskRepliesVisibleIds = this.taskRepliesVisibleIds || new Set();
                    if (this.taskRepliesVisibleIds.has(commentId)) this.taskRepliesVisibleIds.delete(commentId);
                    else this.taskRepliesVisibleIds.add(commentId);
                    this.renderContactTasks();
                    this.renderContactActivities();
                });
            });
            list.querySelectorAll('[data-nexa-task-actions-toggle]').forEach(button => {
                button.addEventListener('click', event => {
                    event.stopPropagation();
                    const menu = button.parentElement.querySelector('[data-nexa-task-actions-menu]');
                    const opening = menu.hidden;
                    list.querySelectorAll('[data-nexa-task-actions-menu]').forEach(item => item.hidden = true);
                    list.querySelectorAll('[data-nexa-task-actions-toggle]').forEach(item => item.setAttribute('aria-expanded', 'false'));
                    menu.hidden = !opening;
                    button.setAttribute('aria-expanded', String(opening));
                });
            });
            list.querySelectorAll('[data-nexa-task-pin]').forEach(button => {
                button.addEventListener('click', () => {
                    const taskId = button.closest('[data-nexa-task-id]').dataset.nexaTaskId;
                    const task = (this.contactTaskRecords || []).find(item => item.id === taskId);
                    this.toggleTaskPinned(taskId, !task?.isPinned, button);
                });
            });
            list.querySelectorAll('[data-nexa-task-delete]').forEach(button => {
                button.addEventListener('click', () => this.openTaskDeleteDialog(button.closest('[data-nexa-task-id]').dataset.nexaTaskId));
            });
            if (this.taskActionsDocumentHandler) document.removeEventListener('click', this.taskActionsDocumentHandler);
            this.taskActionsDocumentHandler = event => {
                if (event.target.closest('[data-nexa-task-actions]')) return;
                this.element.querySelectorAll('[data-nexa-task-actions-menu]').forEach(menu => menu.hidden = true);
                this.element.querySelectorAll('[data-nexa-task-actions-toggle]').forEach(button => button.setAttribute('aria-expanded', 'false'));
            };
            document.addEventListener('click', this.taskActionsDocumentHandler);
        }

        async toggleTaskPinned(taskId, pinned, button) {
            if (!taskId || button?.disabled || button?.dataset.saving === 'true') return;
            button.dataset.saving = 'true';
            button.disabled = true;
            try {
                const model = await this.getModelFactory().create('Task');
                model.id = taskId;
                await model.save({isPinned: pinned}, {patch: true});
                const task = (this.contactTaskRecords || []).find(item => item.id === taskId);
                if (task) task.isPinned = pinned;
                this.renderContactTasks();
                this.renderContactActivities();
                Espo.Ui.success(pinned ? 'Task pinned' : 'Task unpinned');
            } catch (error) {
                button.disabled = false;
                button.dataset.saving = 'false';
                Espo.Ui.error('The task could not be updated. Check your access and try again.');
            }
        }

        openTaskDeleteDialog(taskId) {
            const task = (this.contactTaskRecords || []).find(item => item.id === taskId);
            if (!task?.canDelete) return;
            this.closeTaskDeleteDialog();
            const overlay = document.createElement('div');
            overlay.className = 'nexa-note-delete-overlay';
            overlay.dataset.nexaTaskDeleteDialog = 'true';
            overlay.innerHTML = `<section class="nexa-note-delete-dialog" role="dialog" aria-modal="true" aria-labelledby="nexa-task-delete-title"><header><div><p>Delete task</p><h2 id="nexa-task-delete-title">Delete this task?</h2></div><button type="button" class="nexa-dialog-close" data-nexa-task-delete-close aria-label="Close"><span class="fas fa-times" aria-hidden="true"></span></button></header><div class="nexa-note-delete-content"><p>This task will be removed from the contact timeline. This action cannot be undone from this page.</p><p class="nexa-note-delete-error" role="alert" hidden></p></div><footer><button type="button" class="btn btn-default" data-nexa-task-delete-cancel>Cancel</button><button type="button" class="btn btn-danger" data-nexa-task-delete-confirm><span class="far fa-trash-alt" aria-hidden="true"></span><span>Delete task</span></button></footer></section>`;
            document.body.append(overlay);
            this.taskDeleteDialog = overlay;
            const close = () => this.closeTaskDeleteDialog();
            overlay.querySelector('[data-nexa-task-delete-close]').addEventListener('click', close);
            overlay.querySelector('[data-nexa-task-delete-cancel]').addEventListener('click', close);
            overlay.addEventListener('click', event => { if (event.target === overlay) close(); });
            overlay.addEventListener('keydown', event => { if (event.key === 'Escape') close(); });
            overlay.querySelector('[data-nexa-task-delete-confirm]').addEventListener('click', event => this.deleteContactTask(taskId, event.currentTarget));
            window.setTimeout(() => overlay.querySelector('[data-nexa-task-delete-cancel]')?.focus(), 0);
        }

        closeTaskDeleteDialog() {
            this.taskDeleteDialog?.remove();
            this.taskDeleteDialog = null;
        }

        async deleteContactTask(taskId, button) {
            if (!taskId || button.dataset.saving === 'true') return;
            const error = this.taskDeleteDialog?.querySelector('.nexa-note-delete-error');
            button.dataset.saving = 'true';
            button.disabled = true;
            button.classList.add('is-loading');
            try {
                await Espo.Ajax.deleteRequest(`Task/${encodeURIComponent(taskId)}`);
                this.contactTaskRecords = (this.contactTaskRecords || []).filter(task => task.id !== taskId);
                this.contactTaskComments?.delete(taskId);
                this.contactTaskCommentCounts?.delete(taskId);
                this.collapsedContactTaskIds?.delete(taskId);
                this.closeTaskDeleteDialog();
                this.renderContactTasks();
                this.renderContactActivities();
                Espo.Ui.success('Task deleted');
            } catch (deleteError) {
                if (error) {
                    error.textContent = 'The task could not be deleted. Check your permission and try again.';
                    error.hidden = false;
                }
                button.dataset.saving = 'false';
                button.disabled = false;
                button.classList.remove('is-loading');
            }
        }

        taskFieldEditConfig(field) {
            const configs = {
                name: {mode: 'varchar'},
                description: {mode: 'wysiwyg'},
                status: {mode: 'enum'},
                taskType: {mode: 'enum'},
                priority: {mode: 'enum'},
                assignedUser: {mode: 'assignedUser'},
                dueDateReminder: {mode: 'dueDateReminder'},
            };
            return configs[field] || null;
        }

        async startTaskFieldEdit(taskId, field, row) {
            const task = (this.contactTaskRecords || []).find(item => item.id === taskId);
            const config = this.taskFieldEditConfig(field);
            if (!task || !config || !task._editable?.[field]) {
                Espo.Ui.error(this.translate('Access denied'));
                return;
            }

            this.cancelTaskFieldEdit();

            const display = row.querySelector('[data-nexa-inline-display]');
            const trigger = row.querySelector('[data-nexa-task-inline-trigger]');
            const originalHtml = display.innerHTML;

            this.taskInlineEditSeq = (this.taskInlineEditSeq || 0) + 1;
            const key = `nexaTaskField${this.taskInlineEditSeq}`;
            const hostId = `nexa-task-inline-host-${this.taskInlineEditSeq}`;

            row.classList.add('is-editing');
            trigger.hidden = true;
            display.innerHTML = `<div class="nexa-task-inline-editor" data-nexa-task-inline-host="${hostId}"></div>
                <div class="nexa-task-inline-actions">
                    <button type="button" class="btn btn-default btn-xs" data-nexa-task-inline-cancel>Cancel</button>
                    <button type="button" class="btn btn-primary btn-xs" data-nexa-task-inline-save>Save</button>
                </div>
                <p class="nexa-note-error" data-nexa-task-inline-error role="alert" hidden></p>`;

            this.taskFieldEditState = {
                taskId, field, config, row, display, trigger, originalHtml, key,
                views: [], model: null, saving: false,
            };

            display.querySelector('[data-nexa-task-inline-cancel]').addEventListener('click', () => this.cancelTaskFieldEdit());
            display.querySelector('[data-nexa-task-inline-save]').addEventListener('click', () => this.saveTaskFieldEdit());

            try {
                const model = await this.getModelFactory().create('Task');
                model.id = task.id;
                model.set(task);
                if (!this.taskFieldEditState || this.taskFieldEditState.key !== key) return;
                this.taskFieldEditState.model = model;

                const hostSelector = `[data-nexa-task-inline-host="${hostId}"]`;

                if (config.mode === 'dueDateReminder') {
                    await this.mountTaskDueDateReminderEditor(task, model, key, hostSelector);
                } else if (config.mode === 'assignedUser') {
                    await this.ensureTenantOwnersLoaded();
                    if (!this.taskFieldEditState || this.taskFieldEditState.key !== key) return;
                    const assigneeHost = document.querySelector(hostSelector);
                    this.taskFieldEditState.assigneePicker = this.mountAssigneePicker(assigneeHost, {
                        userId: model.get('assignedUserId'),
                        userName: model.get('assignedUserName'),
                        onChange: (id, name) => {
                            model.set({assignedUserId: id, assignedUserName: name});
                        },
                    });
                } else {
                    const viewPathByMode = {
                        enum: 'views/fields/enum',
                        varchar: 'views/fields/varchar',
                        wysiwyg: 'views/fields/wysiwyg',
                    };
                    const params = config.mode === 'wysiwyg' ? {height: 180, minHeight: 140} : undefined;
                    const view = await this.createView(key, viewPathByMode[config.mode], {
                        fullSelector: hostSelector, model, name: field, mode: 'edit',
                        ...(params ? {params} : {}),
                    });
                    await view.render();
                    if (!this.taskFieldEditState || this.taskFieldEditState.key !== key) return;
                    this.taskFieldEditState.views = [view];
                }
            } catch (error) {
                this.cancelTaskFieldEdit();
                Espo.Ui.error(this.translate('Error occurred'));
            }
        }

        async mountTaskDueDateReminderEditor(task, model, key, hostSelector) {
            const host = document.querySelector(hostSelector);
            if (!host) return;

            host.innerHTML = `
                <div class="nexa-task-field" data-nexa-task-inline-dateend></div>
                <div class="nexa-task-field">
                    <label>Send reminder</label>
                    <select class="form-control" data-nexa-task-inline-reminder>
                        <option value="none">No reminder</option>
                        <option value="at-time">At task due time</option>
                        <option value="30m">30 minutes before</option>
                        <option value="1h">1 hour before</option>
                        <option value="1d">1 day before</option>
                        <option value="1w">1 week before</option>
                        <option value="custom">Custom date &amp; time</option>
                    </select>
                </div>
                <div class="nexa-task-field" data-nexa-task-inline-remind-at-field hidden>
                    <label>Reminder date &amp; time</label>
                    <input type="datetime-local" class="form-control" data-nexa-task-inline-remind-at>
                </div>`;

            const view = await this.createView(key, 'views/fields/datetime-optional', {
                fullSelector: `${hostSelector} [data-nexa-task-inline-dateend]`,
                model,
                name: 'dateEnd',
                mode: 'edit',
                params: {required: true},
            });
            await view.render();
            if (!this.taskFieldEditState || this.taskFieldEditState.key !== key) return;
            this.taskFieldEditState.views = [view];

            const currentSeconds = Array.isArray(task.reminders) && task.reminders.length ?
                (task.reminders[0].seconds ?? 0) : null;
            const reminderSelect = host.querySelector('[data-nexa-task-inline-reminder]');
            reminderSelect.value = this.taskReminderPresetFromSeconds(currentSeconds);
            const remindAtField = host.querySelector('[data-nexa-task-inline-remind-at-field]');
            remindAtField.hidden = reminderSelect.value !== 'custom';
            reminderSelect.addEventListener('change', () => {
                remindAtField.hidden = reminderSelect.value !== 'custom';
            });
        }

        async saveTaskFieldEdit() {
            const state = this.taskFieldEditState;
            if (!state || state.saving) return;

            const error = state.display.querySelector('[data-nexa-task-inline-error]');
            error.hidden = true;

            state.views.forEach(view => view.fetchToModel());

            let attributes = null;

            if (state.field === 'name') {
                const name = String(state.model.get('name') || '').trim();
                if (!name) {
                    error.textContent = 'Enter a task name.';
                    error.hidden = false;
                    return;
                }
                attributes = {name};
            } else if (state.field === 'description') {
                attributes = {description: state.model.get('description') || ''};
            } else if (state.field === 'status' || state.field === 'priority' || state.field === 'taskType') {
                attributes = {[state.field]: state.model.get(state.field)};
            } else if (state.field === 'assignedUser') {
                const assignedUserId = state.model.get('assignedUserId') || null;
                if (!assignedUserId) {
                    error.textContent = 'Choose someone to assign this task to.';
                    error.hidden = false;
                    return;
                }
                attributes = {assignedUserId, assignedUserName: state.model.get('assignedUserName') || null};
            } else if (state.field === 'dueDateReminder') {
                const dateEnd = state.model.get('dateEnd');
                if (!dateEnd) {
                    error.textContent = 'Select a due date and time.';
                    error.hidden = false;
                    return;
                }
                const reminderChoice = state.display.querySelector('[data-nexa-task-inline-reminder]')?.value || 'none';
                let reminders = [];
                if (reminderChoice !== 'none') {
                    const customValue = state.display.querySelector('[data-nexa-task-inline-remind-at]')?.value;
                    const {seconds, error: reminderError} = this.computeTaskReminderSeconds(reminderChoice, dateEnd, customValue);
                    if (reminderError) {
                        error.textContent = reminderError;
                        error.hidden = false;
                        return;
                    }
                    reminders = [{type: 'Popup', seconds}, {type: 'Email', seconds}];
                }
                attributes = {dateEnd, reminders};
            }

            if (!attributes) return;

            state.saving = true;
            const saveButton = state.display.querySelector('[data-nexa-task-inline-save]');
            saveButton.disabled = true;
            saveButton.classList.add('is-loading');

            try {
                await state.model.save(attributes, {patch: true});
                const task = (this.contactTaskRecords || []).find(item => item.id === state.taskId);
                if (task) Object.assign(task, attributes);
                this.cancelTaskFieldEdit({skipRestore: true});
                Espo.Ui.success('Task updated');
                this.renderContactTasks();
                this.renderContactActivities();
            } catch (saveError) {
                error.textContent = 'The task could not be saved. Check your access and try again.';
                error.hidden = false;
                state.saving = false;
                saveButton.disabled = false;
                saveButton.classList.remove('is-loading');
            }
        }

        cancelTaskFieldEdit({skipRestore = false} = {}) {
            const state = this.taskFieldEditState;
            if (!state) return;
            if (this.getView(state.key)) this.clearView(state.key);
            state.assigneePicker?.destroy();
            if (!skipRestore && state.row?.isConnected) {
                state.display.innerHTML = state.originalHtml;
                state.row.classList.remove('is-editing');
                if (state.trigger) state.trigger.hidden = false;
            }
            this.taskFieldEditState = null;
        }

        bindContactMeetingsWorkspace(shell) {
            this.contactMeetingFilter = this.contactMeetingFilter || {
                query: '', period: 'all', owner: 'all', statuses: new Set(),
            };
            this.collapsedContactMeetingIds = this.collapsedContactMeetingIds || new Set();

            shell.querySelector('[data-nexa-create-meeting]')?.addEventListener('click', () => this.openMeetingModal());
            shell.querySelector('[data-nexa-collapse-meetings]')?.addEventListener('click', () => {
                const visible = this.filteredContactMeetings();
                const allCollapsed = visible.length > 0 && visible.every(meeting => this.collapsedContactMeetingIds.has(meeting.id));
                if (allCollapsed) {
                    visible.forEach(meeting => this.collapsedContactMeetingIds.delete(meeting.id));
                } else {
                    visible.forEach(meeting => this.collapsedContactMeetingIds.add(meeting.id));
                }
                this.renderContactMeetings(shell);
            });
            shell.querySelector('[data-nexa-meetings-search]')?.addEventListener('input', event => {
                this.contactMeetingFilter.query = event.currentTarget.value.trim().toLowerCase();
                this.renderContactMeetings(shell);
            });
            shell.querySelector('[data-nexa-meetings-filter-toggle]')?.addEventListener('click', event => {
                const filters = shell.querySelector('[data-nexa-meetings-filters]');
                filters.hidden = !filters.hidden;
                event.currentTarget.setAttribute('aria-expanded', String(!filters.hidden));
            });
            shell.querySelector('[data-nexa-meeting-period-toggle]')?.addEventListener('click', event => {
                event.stopPropagation();
                this.toggleMeetingFilterMenu(shell, 'period');
            });
            shell.querySelector('[data-nexa-meeting-owner-toggle]')?.addEventListener('click', event => {
                event.stopPropagation();
                this.toggleMeetingFilterMenu(shell, 'owner');
            });
            shell.querySelectorAll('[data-nexa-meeting-period]').forEach(button => {
                button.addEventListener('click', () => {
                    this.contactMeetingFilter.period = button.dataset.nexaMeetingPeriod;
                    shell.querySelector('[data-nexa-meeting-period-label]').textContent = button.textContent.trim();
                    this.closeMeetingFilterMenus(shell);
                    this.renderContactMeetings(shell);
                });
            });
            shell.querySelector('[data-nexa-meeting-owner-search]')?.addEventListener('input', event => {
                const term = event.currentTarget.value.trim().toLowerCase();
                shell.querySelectorAll('[data-nexa-meeting-owner-option]').forEach(button => {
                    button.hidden = term && !button.dataset.nexaOwnerSearch.includes(term);
                });
            });
            shell.querySelector('[data-nexa-meeting-status-toggle]')?.addEventListener('click', event => {
                event.stopPropagation();
                this.toggleMeetingFilterMenu(shell, 'status');
            });
            shell.querySelectorAll('[data-nexa-meeting-status-option]').forEach(checkbox => {
                checkbox.addEventListener('change', () => {
                    if (checkbox.checked) this.contactMeetingFilter.statuses.add(checkbox.value);
                    else this.contactMeetingFilter.statuses.delete(checkbox.value);
                    this.updateMeetingFilterLabel(shell, 'status', this.contactMeetingFilter.statuses.size);
                    this.renderContactMeetings(shell);
                });
            });

            if (this.meetingsFilterDocumentHandler) document.removeEventListener('click', this.meetingsFilterDocumentHandler);
            this.meetingsFilterDocumentHandler = event => {
                if (!event.target.closest('[data-nexa-meeting-period-filter], [data-nexa-meeting-owner-filter], [data-nexa-meeting-status-filter]')) {
                    this.closeMeetingFilterMenus(shell);
                }
            };
            document.addEventListener('click', this.meetingsFilterDocumentHandler);
        }

        meetingFilterTypes() {
            return ['period', 'owner', 'status'];
        }

        toggleMeetingFilterMenu(shell, type) {
            const ownMenu = shell.querySelector(`[data-nexa-meeting-${type}-menu]`);
            const ownToggle = shell.querySelector(`[data-nexa-meeting-${type}-toggle]`);
            if (!ownMenu || !ownToggle) return;
            const opening = ownMenu.hidden;
            this.meetingFilterTypes().forEach(otherType => {
                if (otherType === type) return;
                shell.querySelector(`[data-nexa-meeting-${otherType}-menu]`)?.setAttribute('hidden', '');
                shell.querySelector(`[data-nexa-meeting-${otherType}-toggle]`)?.setAttribute('aria-expanded', 'false');
            });
            ownMenu.hidden = !opening;
            ownToggle.setAttribute('aria-expanded', String(opening));
            if (opening && type === 'owner') window.setTimeout(() => ownMenu.querySelector('input')?.focus(), 0);
        }

        closeMeetingFilterMenus(shell) {
            this.meetingFilterTypes().forEach(type => {
                shell.querySelector(`[data-nexa-meeting-${type}-menu]`)?.setAttribute('hidden', '');
                shell.querySelector(`[data-nexa-meeting-${type}-toggle]`)?.setAttribute('aria-expanded', 'false');
            });
        }

        updateMeetingFilterLabel(shell, type, count) {
            const labels = {status: 'Meeting outcome'};
            const label = shell.querySelector(`[data-nexa-meeting-${type}-label]`);
            if (label) label.textContent = count > 0 ? `${labels[type]} (${count})` : labels[type];
        }

        async loadContactMeetingOwners(workspace) {
            const meetingAssignees = (this.contactMeetingRecords || []).map(meeting => ({
                id: meeting.assignedUserId,
                name: meeting.assignedUserName || 'Removed owner',
                isActive: true,
            })).filter(owner => owner.id);

            await this.ensureTenantOwnersLoaded();

            const owners = new Map();
            [...this.contactNoteTenantOwners, ...meetingAssignees].forEach(owner => {
                if (!owners.has(owner.id) || owner.isActive === false) owners.set(owner.id, owner);
            });
            this.contactMeetingOwners = [...owners.values()].sort((a, b) => a.name.localeCompare(b.name));
            const knownIds = new Set(this.contactNoteTenantOwners.map(owner => owner.id));
            this.deactivatedContactOwnerIds = new Set([
                ...(this.deactivatedContactOwnerIds || []),
                ...this.contactMeetingOwners
                    .filter(owner => owner.isActive === false || !knownIds.has(owner.id))
                    .map(owner => owner.id),
            ]);
            this.renderContactMeetingOwnerOptions(workspace);
        }

        renderContactMeetingOwnerOptions(workspace) {
            const container = workspace?.querySelector('[data-nexa-meeting-owner-options]');
            if (!container) return;
            const owners = this.contactMeetingOwners || [];
            container.innerHTML = `
                <button type="button" data-nexa-meeting-owner-option="all" data-nexa-owner-search="all owners">All owners</button>
                <button type="button" data-nexa-meeting-owner-option="me" data-nexa-owner-search="me my activity">Me</button>
                <button type="button" data-nexa-meeting-owner-option="deactivated" data-nexa-owner-search="deactivated removed owners">Deactivated and removed owners</button>
                ${owners.length ? '<p>Owners</p>' : ''}
                ${owners.map(owner => `<button type="button" data-nexa-meeting-owner-option="${this.escape(owner.id)}" data-nexa-owner-search="${this.escape(owner.name.toLowerCase())}">${this.escape(owner.name)}${owner.isActive === false ? ' (deactivated)' : ''}</button>`).join('')}`;
            container.querySelectorAll('[data-nexa-meeting-owner-option]').forEach(button => {
                button.addEventListener('click', () => {
                    const value = button.dataset.nexaMeetingOwnerOption;
                    this.contactMeetingFilter.owner = value;
                    const labels = {
                        all: 'Activity assigned to',
                        me: 'Me',
                        deactivated: 'Deactivated and removed owners',
                    };
                    workspace.querySelector('[data-nexa-meeting-owner-label]').textContent = labels[value] || button.textContent.trim();
                    const search = workspace.querySelector('[data-nexa-meeting-owner-search]');
                    search.value = '';
                    container.querySelectorAll('[data-nexa-meeting-owner-option]').forEach(option => option.hidden = false);
                    this.closeMeetingFilterMenus(workspace);
                    this.renderContactMeetings(workspace);
                });
            });
        }

        async loadContactMeetings(shell = null, newestId = null) {
            const workspace = shell || this.element.querySelector('[data-nexa-contact-workspace]');
            const list = workspace?.querySelector('[data-nexa-meeting-list]');
            const count = workspace?.querySelector('[data-nexa-meeting-count]');
            if (!list || !count) return;

            try {
                const payload = await Espo.Ajax.getRequest('Meeting', {
                    where: [
                        {type: 'linkedWith', attribute: 'contacts', value: [this.model.id]},
                    ],
                    select: 'id,name,status,dateStart,dateEnd,assignedUserId,assignedUserName,description,createdAt,isPinned',
                    maxSize: 200,
                    orderBy: 'dateStart',
                    order: 'asc',
                });
                this.contactMeetingRecords = Array.isArray(payload?.list) ? payload.list : [];
                await Promise.all(this.contactMeetingRecords.map(async meeting => {
                    const model = await this.getModelFactory().create('Meeting');
                    model.set(meeting);
                    model.id = meeting.id;
                    const canEditModel = this.getAcl().checkModel(model, 'edit') === true;
                    meeting._editable = {
                        name: canEditModel && !!this.getAcl().checkField('Meeting', 'name', 'edit'),
                        description: canEditModel && !!this.getAcl().checkField('Meeting', 'description', 'edit'),
                        status: canEditModel && !!this.getAcl().checkField('Meeting', 'status', 'edit'),
                        assignedUser: canEditModel && !!this.getAcl().checkField('Meeting', 'assignedUser', 'edit'),
                        when: canEditModel &&
                            !!this.getAcl().checkField('Meeting', 'dateStart', 'edit') &&
                            !!this.getAcl().checkField('Meeting', 'dateEnd', 'edit'),
                    };
                    meeting.canPin = canEditModel;
                    meeting.canDelete = this.getAcl().checkModel(model, 'delete') === true;
                }));
                this.knownContactMeetingIds = this.knownContactMeetingIds || new Set();
                this.contactMeetingRecords.forEach(meeting => {
                    if (this.knownContactMeetingIds.has(meeting.id)) return;
                    this.knownContactMeetingIds.add(meeting.id);
                    this.collapsedContactMeetingIds?.add(meeting.id);
                });
                this.renderContactMeetings(workspace, newestId);
                this.renderContactActivities(workspace);
                this.renderContactOverviewInsights(workspace);
                this.loadContactMeetingCommentCounts(workspace);
                this.loadContactMeetingOwners(workspace);
            } catch (error) {
                list.innerHTML = `<div class="nexa-note-empty is-error"><span class="fas fa-exclamation-circle" aria-hidden="true"></span><div><strong>Meetings unavailable</strong><p>Refresh the page to try loading them again.</p></div></div>`;
            }
        }

        async loadContactMeetingCommentCounts(workspace) {
            const ids = (this.contactMeetingRecords || []).map(meeting => meeting.id);
            this.contactMeetingCommentCounts = new Map();
            if (!ids.length) {
                this.renderContactMeetings(workspace);
                this.renderContactActivities(workspace);
                return;
            }
            try {
                const payload = await Espo.Ajax.getRequest('Note', {
                    where: [
                        {type: 'equals', attribute: 'parentType', value: 'Meeting'},
                        {type: 'in', attribute: 'parentId', value: ids},
                        {type: 'equals', attribute: 'type', value: 'Post'},
                    ],
                    select: 'id,parentId',
                    maxSize: 200,
                });
                (payload?.list || []).forEach(note => {
                    const current = this.contactMeetingCommentCounts.get(note.parentId) || 0;
                    this.contactMeetingCommentCounts.set(note.parentId, current + 1);
                });
            } catch (error) {
                // Comment counts are a display enhancement only; failures are non-blocking.
            }
            this.renderContactMeetings(workspace);
            this.renderContactActivities(workspace);
        }

        meetingIsOverdue(meeting) {
            if (!meeting.dateEnd || ['Held', 'Not Held'].includes(meeting.status)) return false;
            const date = this.contactNoteDate(meeting.dateEnd);
            return date ? date.getTime() < Date.now() : false;
        }

        filteredContactMeetings() {
            const filter = this.contactMeetingFilter || {query: '', period: 'all', owner: 'all', statuses: new Set()};
            const currentUserId = this.getUser()?.id || this.getUser()?.get?.('id');
            return (this.contactMeetingRecords || []).filter(meeting => {
                const matchesQuery = !filter.query ||
                    `${meeting.name || ''} ${meeting.assignedUserName || ''}`.toLowerCase().includes(filter.query);
                const ownerId = meeting.assignedUserId || '';
                const matchesOwner = !filter.owner || filter.owner === 'all' ||
                    (filter.owner === 'me' && ownerId === currentUserId) ||
                    (filter.owner === 'deactivated' && this.deactivatedContactOwnerIds?.has(ownerId)) ||
                    ownerId === filter.owner;
                const matchesPeriod = this.contactNoteMatchesPeriod(meeting.dateStart || meeting.createdAt, filter.period);
                const matchesStatus = !filter.statuses?.size || filter.statuses.has(meeting.status);
                return matchesQuery && matchesOwner && matchesPeriod && matchesStatus;
            });
        }

        renderContactMeetings(workspace = null, newestId = null) {
            workspace = workspace || this.element.querySelector('[data-nexa-contact-workspace]');
            const list = workspace?.querySelector('[data-nexa-meeting-list]');
            const count = workspace?.querySelector('[data-nexa-meeting-count]');
            if (!list || !count) return;
            this.cancelMeetingFieldEdit();

            const meetings = this.filteredContactMeetings();
            const groups = new Map();
            const pinned = meetings.filter(meeting => meeting.isPinned);
            const chronological = meetings.filter(meeting => !meeting.isPinned);
            if (pinned.length) groups.set('pinned', {label: 'Pinned', meetings: pinned, pinned: true});
            chronological.forEach(meeting => {
                const date = this.contactNoteDate(meeting.dateStart);
                const key = date ? `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}` : 'unknown';
                const label = date
                    ? new Intl.DateTimeFormat(undefined, {month: 'long', year: 'numeric'}).format(date)
                    : 'No date';
                if (!groups.has(key)) groups.set(key, {label, meetings: []});
                groups.get(key).meetings.push(meeting);
            });

            count.textContent = `${meetings.length} ${meetings.length === 1 ? 'meeting' : 'meetings'}`;
            list.innerHTML = meetings.length
                ? [...groups.values()].map(group => `<section class="nexa-note-month${group.pinned ? ' is-pinned-group' : ''}"><h4>${group.pinned ? '<span class="fas fa-thumbtack" aria-hidden="true"></span>' : ''}${this.escape(group.label)}</h4>${group.meetings.map(meeting => this.contactMeetingCard(meeting, meeting.id === newestId)).join('')}</section>`).join('')
                : `<div class="nexa-note-empty"><span class="far fa-calendar" aria-hidden="true"></span><div><strong>No matching meetings</strong><p>Try another search or schedule a new meeting.</p></div></div>`;

            const collapse = workspace.querySelector('[data-nexa-collapse-meetings]');
            const allCollapsed = meetings.length > 0 && meetings.every(meeting => this.collapsedContactMeetingIds?.has(meeting.id));
            if (collapse) collapse.firstChild.textContent = allCollapsed ? 'Expand all ' : 'Collapse all ';

            this.bindContactMeetingList(list);
        }

        contactMeetingCard(meeting, isNewest = false, context = 'meetings') {
            const startLabel = meeting.dateStart ? this.getDateTime().toDisplay(meeting.dateStart) : 'No date set';
            const endLabel = meeting.dateEnd ? this.getDateTime().toDisplay(meeting.dateEnd) : '';
            const timeRange = endLabel
                ? `${this.escape(startLabel)} &ndash; ${this.escape(endLabel)}`
                : this.escape(startLabel);
            const createdLabel = meeting.createdAt ? this.getDateTime().toDisplay(meeting.createdAt) : '';
            const collapsed = this.collapsedContactMeetingIds?.has(meeting.id);
            const overdue = this.meetingIsOverdue(meeting);
            const commentCount = this.contactMeetingCommentCounts?.get(meeting.id) || 0;
            const comments = this.contactMeetingComments?.get(meeting.id) || [];
            const commentsVisible = this.meetingCommentsVisibleIds?.has(meeting.id) === true;
            const nameText = `<span>${this.escape(meeting.name || 'Untitled meeting')}</span>`;
            const editorHost = `${context}-${meeting.id}`;
            const canComment = this.getAcl().checkScope('Note', 'create') === true;
            const canDelete = meeting.canDelete === true;
            const canPin = meeting.canPin === true;
            const deleteHelp = "You don't have permission to delete this meeting. Ask your admin to grant permission.";

            return `<article class="nexa-note-card${isNewest ? ' is-new' : ''}${collapsed ? ' is-collapsed' : ''}${meeting.isPinned ? ' is-pinned' : ''}" data-nexa-meeting-id="${this.escape(meeting.id)}" data-nexa-meeting-context="${this.escape(context)}">
                <header>
                    <button class="nexa-note-toggle" type="button" data-nexa-meeting-toggle aria-expanded="${!collapsed}"><span class="fas fa-chevron-${collapsed ? 'right' : 'down'}" aria-hidden="true"></span>${meeting.isPinned ? '<span class="fas fa-thumbtack nexa-note-pinned-icon" aria-label="Pinned meeting"></span>' : ''}<span class="nexa-note-kind">Meeting</span><span>${this.escape(meeting.name || 'Untitled meeting')}</span>${overdue ? '<span class="nexa-note-overdue-badge" title="Overdue"><span class="fas fa-exclamation-circle" aria-hidden="true"></span></span>' : ''}${commentCount ? `<span class="nexa-note-comment-count"><span class="far fa-comment" aria-hidden="true"></span>${commentCount}</span>` : ''}</button>
                    <div class="nexa-note-header-meta"><div class="nexa-note-actions" data-nexa-meeting-actions${collapsed ? ' hidden' : ''}><button type="button" class="nexa-note-actions-toggle" data-nexa-meeting-actions-toggle aria-expanded="false">Actions <span class="fas fa-caret-down" aria-hidden="true"></span></button><div class="nexa-note-actions-menu" data-nexa-meeting-actions-menu hidden><button type="button" data-nexa-meeting-pin${canPin ? '' : ' disabled aria-disabled="true"'}><span class="fas fa-thumbtack" aria-hidden="true"></span>${meeting.isPinned ? 'Unpin' : 'Pin'}</button>${canDelete ? '<button type="button" class="is-danger" data-nexa-meeting-delete><span class="far fa-trash-alt" aria-hidden="true"></span>Delete</button>' : `<span class="nexa-note-action-disabled" data-tooltip="${this.escape(deleteHelp)}" tabindex="0"><button type="button" class="is-danger" disabled aria-disabled="true"><span class="far fa-trash-alt" aria-hidden="true"></span>Delete</button></span>`}</div></div><time>${this.escape(createdLabel)}</time></div>
                </header>
                <p class="nexa-note-preview"${collapsed ? '' : ' hidden'}>${this.escape(meeting.name || 'Untitled meeting')} &middot; ${this.escape(startLabel)}</p>
                <div class="nexa-note-details" data-nexa-meeting-details${collapsed ? ' hidden' : ''}>
                    ${this.meetingTitleRow(meeting, nameText)}
                    <dl class="nexa-activity-audit-details">
                        ${this.meetingInlineFactRow(meeting, 'when', 'Date & time', timeRange)}
                    </dl>
                    <div class="nexa-task-fact-line">
                        ${this.meetingInlineFactItem(meeting, 'status', 'Status', this.meetingStatusBadge(meeting.status))}
                        ${this.meetingInlineFactItem(meeting, 'assignedUser', 'Assigned to', this.escape(meeting.assignedUserName || 'Unassigned'))}
                    </div>
                    ${this.meetingNotesSection(meeting)}
                    <footer>
                        ${canComment ? '<button type="button" data-nexa-comment-toggle><span class="far fa-comment" aria-hidden="true"></span><span>Add comment</span></button>' : ''}
                        ${commentCount ? `<button type="button" class="nexa-task-reply-toggle" data-nexa-meeting-comments-visibility-toggle>${commentsVisible ? 'Hide comments' : `Show comments (${commentCount})`}</button>` : '<span>0 comments</span>'}
                    </footer>
                    <div class="nexa-note-comments"${commentsVisible ? '' : ' hidden'}>${comments.map(comment => this.contactMeetingComment(comment, context)).join('')}</div>
                    <form class="nexa-note-comment-form" data-nexa-comment-form hidden>
                        <div class="nexa-native-rich-editor nexa-comment-editor" data-nexa-comment-editor-host="${this.escape(editorHost)}" aria-label="Comment"><div class="nexa-note-editor-loading"><span class="fas fa-circle-notch fa-spin" aria-hidden="true"></span><span>Loading editor</span></div></div>
                        <div><button type="button" class="btn btn-default btn-xs" data-nexa-comment-cancel>Cancel</button><button type="submit" class="btn btn-primary btn-xs">Comment</button></div>
                        <p role="alert" data-nexa-comment-error hidden></p>
                    </form>
                </div>
            </article>`;
        }

        meetingTitleRow(meeting, nameText) {
            if (!meeting._editable?.name) {
                return `<p class="nexa-task-title">${nameText}</p>`;
            }

            return `<div class="nexa-task-title nexa-inline-detail nexa-inline-detail--title" data-nexa-meeting-inline-field data-field="name">
                <span class="nexa-inline-detail-display" data-nexa-inline-display>${nameText}</span>
                <button type="button" class="nexa-inline-detail-trigger" data-nexa-meeting-inline-trigger aria-label="Edit meeting name"><span class="fas fa-pencil-alt" aria-hidden="true"></span></button>
            </div>`;
        }

        meetingNotesSection(meeting) {
            const bodyHtml = meeting.description ? this.formatNoteContent(meeting.description) : '<span class="nexa-record-empty">No description added</span>';
            if (!meeting._editable?.description) {
                return `<div class="nexa-task-notes-section"><h5 class="nexa-task-notes-heading">Description</h5><div class="nexa-note-body">${bodyHtml}</div></div>`;
            }

            return `<div class="nexa-task-notes-section nexa-inline-detail nexa-inline-detail--notes" data-nexa-meeting-inline-field data-field="description">
                <div class="nexa-task-notes-heading-row"><h5 class="nexa-task-notes-heading">Description</h5><button type="button" class="nexa-inline-detail-trigger" data-nexa-meeting-inline-trigger aria-label="Edit description"><span class="fas fa-pencil-alt" aria-hidden="true"></span></button></div>
                <div class="nexa-note-body" data-nexa-inline-display>${bodyHtml}</div>
            </div>`;
        }

        meetingInlineFactItem(meeting, field, label, displayHtml) {
            if (!meeting._editable?.[field]) {
                return `<span class="nexa-task-fact-item"><span class="nexa-task-fact-label">${this.escape(label)}</span><span class="nexa-task-fact-value-row"><strong>${displayHtml}</strong></span></span>`;
            }

            return `<span class="nexa-task-fact-item nexa-inline-detail nexa-inline-detail--compact" data-nexa-meeting-inline-field data-field="${this.escape(field)}">
                <span class="nexa-task-fact-label">${this.escape(label)}</span>
                <span class="nexa-task-fact-value-row">
                    <strong class="nexa-inline-detail-display" data-nexa-inline-display>${displayHtml}</strong>
                    <button type="button" class="nexa-inline-detail-trigger" data-nexa-meeting-inline-trigger aria-label="Edit ${this.escape(label)}"><span class="fas fa-pencil-alt" aria-hidden="true"></span></button>
                </span>
            </span>`;
        }

        meetingInlineFactRow(meeting, field, label, displayHtml) {
            if (!meeting._editable?.[field]) {
                return `<div><dt>${this.escape(label)}</dt><dd>${displayHtml}</dd></div>`;
            }

            return `<div class="nexa-inline-detail nexa-inline-detail--compact" data-nexa-meeting-inline-field data-field="${this.escape(field)}">
                <dt>${this.escape(label)}</dt>
                <dd><span class="nexa-inline-detail-display" data-nexa-inline-display>${displayHtml}</span><button type="button" class="nexa-inline-detail-trigger" data-nexa-meeting-inline-trigger aria-label="Edit ${this.escape(label)}"><span class="fas fa-pencil-alt" aria-hidden="true"></span></button></dd>
            </div>`;
        }

        contactMeetingComment(comment, context) {
            const author = comment.createdByName || 'Team member';
            const createdAt = comment.createdAt ? this.getDateTime().toDisplay(comment.createdAt) : '';
            const replies = comment.replies || [];
            const repliesVisible = this.meetingRepliesVisibleIds?.has(comment.id) === true;
            const replyEditorHost = `${context}-reply-${comment.id}`;
            const canComment = this.getAcl().checkScope('Note', 'create') === true;

            return `<div class="nexa-note-comment" data-nexa-meeting-comment-id="${this.escape(comment.id)}">
                <div><strong>${this.escape(author)}</strong><time>${this.escape(createdAt)}</time></div>
                <p>${this.formatNoteContent(comment.content)}</p>
                <div class="nexa-task-comment-actions">
                    ${canComment ? '<button type="button" class="nexa-task-reply-toggle" data-nexa-meeting-reply-toggle>Reply</button>' : ''}
                    ${replies.length ? `<button type="button" class="nexa-task-reply-toggle" data-nexa-meeting-replies-visibility-toggle>${repliesVisible ? 'Hide replies' : `Show replies (${replies.length})`}</button>` : ''}
                </div>
                ${replies.length ? `<div class="nexa-task-comment-replies"${repliesVisible ? '' : ' hidden'}>${replies.map(reply => this.contactMeetingReply(reply)).join('')}</div>` : ''}
                <form class="nexa-note-comment-form nexa-task-reply-form" data-nexa-reply-form hidden>
                    <div class="nexa-native-rich-editor nexa-comment-editor" data-nexa-comment-editor-host="${this.escape(replyEditorHost)}" aria-label="Reply"><div class="nexa-note-editor-loading"><span class="fas fa-circle-notch fa-spin" aria-hidden="true"></span><span>Loading editor</span></div></div>
                    <div><button type="button" class="btn btn-default btn-xs" data-nexa-reply-cancel>Cancel</button><button type="submit" class="btn btn-primary btn-xs">Reply</button></div>
                    <p role="alert" data-nexa-reply-error hidden></p>
                </form>
            </div>`;
        }

        contactMeetingReply(reply) {
            const author = reply.createdByName || 'Team member';
            const createdAt = reply.createdAt ? this.getDateTime().toDisplay(reply.createdAt) : '';
            return `<div class="nexa-note-comment nexa-task-comment-reply"><div><strong>${this.escape(author)}</strong><time>${this.escape(createdAt)}</time></div><p>${this.formatNoteContent(reply.content)}</p></div>`;
        }

        async loadMeetingComments(meetingId, {force = false} = {}) {
            this.contactMeetingComments = this.contactMeetingComments || new Map();
            if (!force && this.contactMeetingComments.has(meetingId)) return;

            try {
                const payload = await Espo.Ajax.getRequest('Note', {
                    where: [
                        {type: 'equals', attribute: 'parentType', value: 'Meeting'},
                        {type: 'equals', attribute: 'parentId', value: meetingId},
                        {type: 'equals', attribute: 'type', value: 'Post'},
                    ],
                    select: 'id,post,createdAt,createdById,createdByName',
                    orderBy: 'createdAt',
                    order: 'asc',
                    maxSize: 200,
                });
                const records = payload?.list || [];
                const topLevel = [];
                const repliesByParent = new Map();

                records.forEach(record => {
                    const post = String(record.post || '');
                    const replyMatch = post.match(/^<!-- nexa-meeting-reply:([A-Za-z0-9_-]+) -->\s*\n?/);
                    if (replyMatch) {
                        const list = repliesByParent.get(replyMatch[1]) || [];
                        list.push({...record, content: post.replace(replyMatch[0], '')});
                        repliesByParent.set(replyMatch[1], list);
                        return;
                    }
                    topLevel.push({...record, content: post});
                });
                topLevel.forEach(comment => {
                    comment.replies = repliesByParent.get(comment.id) || [];
                });

                this.contactMeetingComments.set(meetingId, topLevel);
                this.contactMeetingCommentCounts = this.contactMeetingCommentCounts || new Map();
                this.contactMeetingCommentCounts.set(meetingId, records.length);
            } catch (error) {
                this.contactMeetingComments.set(meetingId, []);
            }
        }

        async createMeetingStreamNote(meetingId, post) {
            const note = await this.getModelFactory().create('Note');
            note.set({
                type: 'Post',
                post,
                parentType: 'Meeting',
                parentId: meetingId,
            });
            await note.save(null);
            return note;
        }

        async saveMeetingComment(meetingId, form) {
            if (form.dataset.saving === 'true') return;
            const context = form.closest('[data-nexa-meeting-context]')?.dataset.nexaMeetingContext || 'meetings';
            const editor = this.contactCommentEditors?.get(`${context}-${meetingId}`);
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
                const note = await this.createMeetingStreamNote(meetingId, content);
                this.contactMeetingComments = this.contactMeetingComments || new Map();
                const comments = this.contactMeetingComments.get(meetingId) || [];
                comments.push({
                    id: note.id,
                    content,
                    replies: [],
                    createdAt: note.get('createdAt') || new Date().toISOString(),
                    createdById: this.getUser().id,
                    createdByName: this.getUser().get('name'),
                });
                this.contactMeetingComments.set(meetingId, comments);
                this.contactMeetingCommentCounts = this.contactMeetingCommentCounts || new Map();
                this.contactMeetingCommentCounts.set(meetingId, comments.length);
                this.clearContactCommentEditor(meetingId, context);
                form.hidden = true;
                this.meetingCommentsVisibleIds = this.meetingCommentsVisibleIds || new Set();
                this.meetingCommentsVisibleIds.add(meetingId);
                this.renderContactMeetings();
                this.renderContactActivities();
            } catch (saveError) {
                error.textContent = 'The comment could not be saved.';
                error.hidden = false;
                form.dataset.saving = 'false';
                form.querySelector('button[type="submit"]').disabled = false;
            }
        }

        async saveMeetingReply(meetingId, commentId, form) {
            if (form.dataset.saving === 'true') return;
            const context = form.closest('[data-nexa-meeting-context]')?.dataset.nexaMeetingContext || 'meetings';
            const editor = this.contactCommentEditors?.get(`${context}-reply-${commentId}`);
            if (!editor) return;
            editor.view.fetchToModel();
            const content = String(editor.model.get('post') || '').trim();
            const error = form.querySelector('[data-nexa-reply-error]');
            if (this.richTextIsEmpty(content)) {
                error.textContent = 'Enter a reply before saving.';
                error.hidden = false;
                return;
            }

            form.dataset.saving = 'true';
            form.querySelector('button[type="submit"]').disabled = true;
            error.hidden = true;
            try {
                await this.createMeetingStreamNote(meetingId, `<!-- nexa-meeting-reply:${commentId} -->\n${content}`);
                this.clearContactCommentEditor(`reply-${commentId}`, context);
                form.hidden = true;
                await this.loadMeetingComments(meetingId, {force: true});
                this.meetingCommentsVisibleIds = this.meetingCommentsVisibleIds || new Set();
                this.meetingCommentsVisibleIds.add(meetingId);
                this.meetingRepliesVisibleIds = this.meetingRepliesVisibleIds || new Set();
                this.meetingRepliesVisibleIds.add(commentId);
                this.renderContactMeetings();
                this.renderContactActivities();
            } catch (saveError) {
                error.textContent = 'The reply could not be saved.';
                error.hidden = false;
                form.dataset.saving = 'false';
                form.querySelector('button[type="submit"]').disabled = false;
            }
        }

        meetingStatusBadge(value) {
            if (!value) return '<span class="nexa-record-empty">Not recorded</span>';

            const statusClasses = {Planned: 'planned', Held: 'held', 'Not Held': 'not-held'};
            const label = this.getLanguage().translateOption(value, 'status', 'Meeting') || value;
            return `<span class="nexa-meeting-status nexa-meeting-status--${statusClasses[value] || 'other'}">${this.escape(label)}</span>`;
        }

        bindContactMeetingList(list, context = 'meetings') {
            list.querySelectorAll('[data-nexa-meeting-inline-trigger]').forEach(button => {
                button.addEventListener('click', () => {
                    const row = button.closest('[data-nexa-meeting-inline-field]');
                    const meetingId = row.closest('[data-nexa-meeting-id]').dataset.nexaMeetingId;
                    const field = row.dataset.field;
                    this.startMeetingFieldEdit(meetingId, field, row);
                });
            });
            list.querySelectorAll('[data-nexa-meeting-toggle]').forEach(button => {
                button.addEventListener('click', () => {
                    const card = button.closest('[data-nexa-meeting-id]');
                    const id = card.dataset.nexaMeetingId;
                    const expanding = this.collapsedContactMeetingIds.has(id);
                    if (expanding) this.collapsedContactMeetingIds.delete(id);
                    else this.collapsedContactMeetingIds.add(id);
                    if (expanding && !this.contactMeetingComments?.has(id)) {
                        this.loadMeetingComments(id).then(() => {
                            this.renderContactMeetings();
                            this.renderContactActivities();
                        });
                    }
                    this.renderContactMeetings();
                    this.renderContactActivities();
                });
            });
            list.querySelectorAll('[data-nexa-comment-toggle]').forEach(button => {
                button.addEventListener('click', () => {
                    const card = button.closest('[data-nexa-meeting-id]');
                    const form = card.querySelector('[data-nexa-comment-form]');
                    form.hidden = false;
                    this.mountContactCommentEditor(card.dataset.nexaMeetingId, form, context);
                });
            });
            list.querySelectorAll('[data-nexa-comment-cancel]').forEach(button => {
                button.addEventListener('click', () => {
                    const form = button.closest('[data-nexa-comment-form]');
                    this.clearContactCommentEditor(form.closest('[data-nexa-meeting-id]').dataset.nexaMeetingId, context);
                    form.hidden = true;
                });
            });
            list.querySelectorAll('[data-nexa-comment-form]').forEach(form => {
                form.addEventListener('submit', event => {
                    event.preventDefault();
                    this.saveMeetingComment(form.closest('[data-nexa-meeting-id]').dataset.nexaMeetingId, form);
                });
            });
            list.querySelectorAll('[data-nexa-meeting-reply-toggle]').forEach(button => {
                button.addEventListener('click', () => {
                    const commentEl = button.closest('[data-nexa-meeting-comment-id]');
                    const commentId = commentEl.dataset.nexaMeetingCommentId;
                    const form = commentEl.querySelector('[data-nexa-reply-form]');
                    form.hidden = false;
                    this.mountContactCommentEditor(`reply-${commentId}`, form, context);
                });
            });
            list.querySelectorAll('[data-nexa-reply-cancel]').forEach(button => {
                button.addEventListener('click', () => {
                    const form = button.closest('[data-nexa-reply-form]');
                    const commentId = form.closest('[data-nexa-meeting-comment-id]').dataset.nexaMeetingCommentId;
                    this.clearContactCommentEditor(`reply-${commentId}`, context);
                    form.hidden = true;
                });
            });
            list.querySelectorAll('[data-nexa-reply-form]').forEach(form => {
                form.addEventListener('submit', event => {
                    event.preventDefault();
                    const meetingId = form.closest('[data-nexa-meeting-id]').dataset.nexaMeetingId;
                    const commentId = form.closest('[data-nexa-meeting-comment-id]').dataset.nexaMeetingCommentId;
                    this.saveMeetingReply(meetingId, commentId, form);
                });
            });
            list.querySelectorAll('[data-nexa-meeting-comments-visibility-toggle]').forEach(button => {
                button.addEventListener('click', () => {
                    const meetingId = button.closest('[data-nexa-meeting-id]').dataset.nexaMeetingId;
                    this.meetingCommentsVisibleIds = this.meetingCommentsVisibleIds || new Set();
                    if (this.meetingCommentsVisibleIds.has(meetingId)) this.meetingCommentsVisibleIds.delete(meetingId);
                    else this.meetingCommentsVisibleIds.add(meetingId);
                    this.renderContactMeetings();
                    this.renderContactActivities();
                });
            });
            list.querySelectorAll('[data-nexa-meeting-replies-visibility-toggle]').forEach(button => {
                button.addEventListener('click', () => {
                    const commentId = button.closest('[data-nexa-meeting-comment-id]').dataset.nexaMeetingCommentId;
                    this.meetingRepliesVisibleIds = this.meetingRepliesVisibleIds || new Set();
                    if (this.meetingRepliesVisibleIds.has(commentId)) this.meetingRepliesVisibleIds.delete(commentId);
                    else this.meetingRepliesVisibleIds.add(commentId);
                    this.renderContactMeetings();
                    this.renderContactActivities();
                });
            });
            list.querySelectorAll('[data-nexa-meeting-actions-toggle]').forEach(button => {
                button.addEventListener('click', event => {
                    event.stopPropagation();
                    const menu = button.parentElement.querySelector('[data-nexa-meeting-actions-menu]');
                    const opening = menu.hidden;
                    list.querySelectorAll('[data-nexa-meeting-actions-menu]').forEach(item => item.hidden = true);
                    list.querySelectorAll('[data-nexa-meeting-actions-toggle]').forEach(item => item.setAttribute('aria-expanded', 'false'));
                    menu.hidden = !opening;
                    button.setAttribute('aria-expanded', String(opening));
                });
            });
            list.querySelectorAll('[data-nexa-meeting-pin]').forEach(button => {
                button.addEventListener('click', () => {
                    const meetingId = button.closest('[data-nexa-meeting-id]').dataset.nexaMeetingId;
                    const meeting = (this.contactMeetingRecords || []).find(item => item.id === meetingId);
                    this.toggleMeetingPinned(meetingId, !meeting?.isPinned, button);
                });
            });
            list.querySelectorAll('[data-nexa-meeting-delete]').forEach(button => {
                button.addEventListener('click', () => this.openMeetingDeleteDialog(button.closest('[data-nexa-meeting-id]').dataset.nexaMeetingId));
            });
            if (this.meetingActionsDocumentHandler) document.removeEventListener('click', this.meetingActionsDocumentHandler);
            this.meetingActionsDocumentHandler = event => {
                if (event.target.closest('[data-nexa-meeting-actions]')) return;
                this.element.querySelectorAll('[data-nexa-meeting-actions-menu]').forEach(menu => menu.hidden = true);
                this.element.querySelectorAll('[data-nexa-meeting-actions-toggle]').forEach(button => button.setAttribute('aria-expanded', 'false'));
            };
            document.addEventListener('click', this.meetingActionsDocumentHandler);
        }

        async toggleMeetingPinned(meetingId, pinned, button) {
            if (!meetingId || button?.disabled || button?.dataset.saving === 'true') return;
            button.dataset.saving = 'true';
            button.disabled = true;
            try {
                const model = await this.getModelFactory().create('Meeting');
                model.id = meetingId;
                await model.save({isPinned: pinned}, {patch: true});
                const meeting = (this.contactMeetingRecords || []).find(item => item.id === meetingId);
                if (meeting) meeting.isPinned = pinned;
                this.renderContactMeetings();
                this.renderContactActivities();
                Espo.Ui.success(pinned ? 'Meeting pinned' : 'Meeting unpinned');
            } catch (error) {
                button.disabled = false;
                button.dataset.saving = 'false';
                Espo.Ui.error('The meeting could not be updated. Check your access and try again.');
            }
        }

        openMeetingDeleteDialog(meetingId) {
            const meeting = (this.contactMeetingRecords || []).find(item => item.id === meetingId);
            if (!meeting?.canDelete) return;
            this.closeMeetingDeleteDialog();
            const overlay = document.createElement('div');
            overlay.className = 'nexa-note-delete-overlay';
            overlay.dataset.nexaMeetingDeleteDialog = 'true';
            overlay.innerHTML = `<section class="nexa-note-delete-dialog" role="dialog" aria-modal="true" aria-labelledby="nexa-meeting-delete-title"><header><div><p>Delete meeting</p><h2 id="nexa-meeting-delete-title">Delete this meeting?</h2></div><button type="button" class="nexa-dialog-close" data-nexa-meeting-delete-close aria-label="Close"><span class="fas fa-times" aria-hidden="true"></span></button></header><div class="nexa-note-delete-content"><p>This meeting will be removed from the contact timeline. This action cannot be undone from this page.</p><p class="nexa-note-delete-error" role="alert" hidden></p></div><footer><button type="button" class="btn btn-default" data-nexa-meeting-delete-cancel>Cancel</button><button type="button" class="btn btn-danger" data-nexa-meeting-delete-confirm><span class="far fa-trash-alt" aria-hidden="true"></span><span>Delete meeting</span></button></footer></section>`;
            document.body.append(overlay);
            this.meetingDeleteDialog = overlay;
            const close = () => this.closeMeetingDeleteDialog();
            overlay.querySelector('[data-nexa-meeting-delete-close]').addEventListener('click', close);
            overlay.querySelector('[data-nexa-meeting-delete-cancel]').addEventListener('click', close);
            overlay.addEventListener('click', event => { if (event.target === overlay) close(); });
            overlay.addEventListener('keydown', event => { if (event.key === 'Escape') close(); });
            overlay.querySelector('[data-nexa-meeting-delete-confirm]').addEventListener('click', event => this.deleteContactMeeting(meetingId, event.currentTarget));
            window.setTimeout(() => overlay.querySelector('[data-nexa-meeting-delete-cancel]')?.focus(), 0);
        }

        closeMeetingDeleteDialog() {
            this.meetingDeleteDialog?.remove();
            this.meetingDeleteDialog = null;
        }

        async deleteContactMeeting(meetingId, button) {
            if (!meetingId || button.dataset.saving === 'true') return;
            const error = this.meetingDeleteDialog?.querySelector('.nexa-note-delete-error');
            button.dataset.saving = 'true';
            button.disabled = true;
            button.classList.add('is-loading');
            try {
                await Espo.Ajax.deleteRequest(`Meeting/${encodeURIComponent(meetingId)}`);
                this.contactMeetingRecords = (this.contactMeetingRecords || []).filter(meeting => meeting.id !== meetingId);
                this.contactMeetingComments?.delete(meetingId);
                this.contactMeetingCommentCounts?.delete(meetingId);
                this.collapsedContactMeetingIds?.delete(meetingId);
                this.meetingCommentsVisibleIds?.delete(meetingId);
                this.closeMeetingDeleteDialog();
                this.renderContactMeetings();
                this.renderContactActivities();
                Espo.Ui.success('Meeting deleted');
            } catch (deleteError) {
                if (error) {
                    error.textContent = 'The meeting could not be deleted. Check your permission and try again.';
                    error.hidden = false;
                }
                button.dataset.saving = 'false';
                button.disabled = false;
                button.classList.remove('is-loading');
            }
        }

        meetingFieldEditConfig(field) {
            const configs = {
                name: {mode: 'varchar'},
                description: {mode: 'wysiwyg'},
                status: {mode: 'enum'},
                assignedUser: {mode: 'assignedUser'},
                when: {mode: 'when'},
            };
            return configs[field] || null;
        }

        async startMeetingFieldEdit(meetingId, field, row) {
            const meeting = (this.contactMeetingRecords || []).find(item => item.id === meetingId);
            const config = this.meetingFieldEditConfig(field);
            if (!meeting || !config || !meeting._editable?.[field]) {
                Espo.Ui.error(this.translate('Access denied'));
                return;
            }

            this.cancelMeetingFieldEdit();

            const display = row.querySelector('[data-nexa-inline-display]');
            const trigger = row.querySelector('[data-nexa-meeting-inline-trigger]');
            const originalHtml = display.innerHTML;

            this.meetingInlineEditSeq = (this.meetingInlineEditSeq || 0) + 1;
            const key = `nexaMeetingField${this.meetingInlineEditSeq}`;
            const hostId = `nexa-meeting-inline-host-${this.meetingInlineEditSeq}`;

            row.classList.add('is-editing');
            trigger.hidden = true;
            display.innerHTML = `<div class="nexa-task-inline-editor" data-nexa-meeting-inline-host="${hostId}"></div>
                <div class="nexa-task-inline-actions">
                    <button type="button" class="btn btn-default btn-xs" data-nexa-meeting-inline-cancel>Cancel</button>
                    <button type="button" class="btn btn-primary btn-xs" data-nexa-meeting-inline-save>Save</button>
                </div>
                <p class="nexa-note-error" data-nexa-meeting-inline-error role="alert" hidden></p>`;

            this.meetingFieldEditState = {
                meetingId, field, config, row, display, trigger, originalHtml, key,
                views: [], viewKeys: [key], model: null, saving: false,
            };

            display.querySelector('[data-nexa-meeting-inline-cancel]').addEventListener('click', () => this.cancelMeetingFieldEdit());
            display.querySelector('[data-nexa-meeting-inline-save]').addEventListener('click', () => this.saveMeetingFieldEdit());

            try {
                const model = await this.getModelFactory().create('Meeting');
                model.id = meeting.id;
                model.set(meeting);
                if (!this.meetingFieldEditState || this.meetingFieldEditState.key !== key) return;
                this.meetingFieldEditState.model = model;

                const hostSelector = `[data-nexa-meeting-inline-host="${hostId}"]`;

                if (config.mode === 'when') {
                    await this.mountMeetingWhenEditor(model, key, hostSelector);
                } else if (config.mode === 'assignedUser') {
                    await this.ensureTenantOwnersLoaded();
                    if (!this.meetingFieldEditState || this.meetingFieldEditState.key !== key) return;
                    const assigneeHost = document.querySelector(hostSelector);
                    this.meetingFieldEditState.assigneePicker = this.mountAssigneePicker(assigneeHost, {
                        userId: model.get('assignedUserId'),
                        userName: model.get('assignedUserName'),
                        onChange: (id, name) => {
                            model.set({assignedUserId: id, assignedUserName: name});
                        },
                    });
                } else {
                    const viewPathByMode = {
                        enum: 'views/fields/enum',
                        varchar: 'views/fields/varchar',
                        wysiwyg: 'views/fields/wysiwyg',
                    };
                    const params = config.mode === 'wysiwyg' ? {height: 180, minHeight: 140} : undefined;
                    const view = await this.createView(key, viewPathByMode[config.mode], {
                        fullSelector: hostSelector, model, name: field, mode: 'edit',
                        ...(params ? {params} : {}),
                    });
                    await view.render();
                    if (!this.meetingFieldEditState || this.meetingFieldEditState.key !== key) return;
                    this.meetingFieldEditState.views = [view];
                }
            } catch (error) {
                this.cancelMeetingFieldEdit();
                Espo.Ui.error(this.translate('Error occurred'));
            }
        }

        async mountMeetingWhenEditor(model, key, hostSelector) {
            const host = document.querySelector(hostSelector);
            if (!host) return;

            host.innerHTML = `
                <div class="nexa-task-field" data-nexa-meeting-inline-datestart></div>
                <div class="nexa-task-field" data-nexa-meeting-inline-dateend></div>`;

            const startKey = `${key}Start`;
            const endKey = `${key}End`;
            this.meetingFieldEditState.viewKeys = [startKey, endKey];

            const startView = await this.createView(startKey, 'views/fields/datetime-optional', {
                fullSelector: `${hostSelector} [data-nexa-meeting-inline-datestart]`,
                model, name: 'dateStart', mode: 'edit', params: {required: true},
            });
            await startView.render();
            if (!this.meetingFieldEditState || this.meetingFieldEditState.key !== key) return;

            const endView = await this.createView(endKey, 'views/fields/datetime-optional', {
                fullSelector: `${hostSelector} [data-nexa-meeting-inline-dateend]`,
                model, name: 'dateEnd', mode: 'edit', params: {required: true},
            });
            await endView.render();
            if (!this.meetingFieldEditState || this.meetingFieldEditState.key !== key) return;

            this.meetingFieldEditState.views = [startView, endView];
        }

        async saveMeetingFieldEdit() {
            const state = this.meetingFieldEditState;
            if (!state || state.saving) return;

            const error = state.display.querySelector('[data-nexa-meeting-inline-error]');
            error.hidden = true;

            state.views.forEach(view => view.fetchToModel());

            let attributes = null;

            if (state.field === 'name') {
                const name = String(state.model.get('name') || '').trim();
                if (!name) {
                    error.textContent = 'Enter a meeting name.';
                    error.hidden = false;
                    return;
                }
                attributes = {name};
            } else if (state.field === 'description') {
                attributes = {description: state.model.get('description') || ''};
            } else if (state.field === 'status') {
                attributes = {status: state.model.get('status')};
            } else if (state.field === 'assignedUser') {
                const assignedUserId = state.model.get('assignedUserId') || null;
                if (!assignedUserId) {
                    error.textContent = 'Choose someone to assign this meeting to.';
                    error.hidden = false;
                    return;
                }
                attributes = {assignedUserId, assignedUserName: state.model.get('assignedUserName') || null};
            } else if (state.field === 'when') {
                const dateStart = state.model.get('dateStart');
                const dateEnd = state.model.get('dateEnd');
                if (!dateStart || !dateEnd) {
                    error.textContent = 'Select a start and end date and time.';
                    error.hidden = false;
                    return;
                }
                if (new Date(dateEnd).getTime() < new Date(dateStart).getTime()) {
                    error.textContent = 'The end date and time must be after the start.';
                    error.hidden = false;
                    return;
                }
                attributes = {dateStart, dateEnd};
            }

            if (!attributes) return;

            state.saving = true;
            const saveButton = state.display.querySelector('[data-nexa-meeting-inline-save]');
            saveButton.disabled = true;
            saveButton.classList.add('is-loading');

            try {
                await state.model.save(attributes, {patch: true});
                const meeting = (this.contactMeetingRecords || []).find(item => item.id === state.meetingId);
                if (meeting) Object.assign(meeting, attributes);
                this.cancelMeetingFieldEdit({skipRestore: true});
                Espo.Ui.success('Meeting updated');
                this.renderContactMeetings();
                this.renderContactActivities();
            } catch (saveError) {
                error.textContent = 'The meeting could not be saved. Check your access and try again.';
                error.hidden = false;
                state.saving = false;
                saveButton.disabled = false;
                saveButton.classList.remove('is-loading');
            }
        }

        cancelMeetingFieldEdit({skipRestore = false} = {}) {
            const state = this.meetingFieldEditState;
            if (!state) return;
            (state.viewKeys || [state.key]).forEach(viewKey => {
                if (this.getView(viewKey)) this.clearView(viewKey);
            });
            state.assigneePicker?.destroy();
            if (!skipRestore && state.row?.isConnected) {
                state.display.innerHTML = state.originalHtml;
                state.row.classList.remove('is-editing');
                if (state.trigger) state.trigger.hidden = false;
            }
            this.meetingFieldEditState = null;
        }

        bindContactCallsWorkspace(shell) {
            this.contactCallFilter = this.contactCallFilter || {
                query: '', period: 'all', owner: 'all', statuses: new Set(),
            };
            this.collapsedContactCallIds = this.collapsedContactCallIds || new Set();

            shell.querySelector('[data-nexa-create-callrecord]')?.addEventListener('click', () => this.openScheduleCallModal());
            shell.querySelector('[data-nexa-collapse-callrecords]')?.addEventListener('click', () => {
                const visible = this.filteredContactCalls();
                const allCollapsed = visible.length > 0 && visible.every(call => this.collapsedContactCallIds.has(call.id));
                if (allCollapsed) {
                    visible.forEach(call => this.collapsedContactCallIds.delete(call.id));
                } else {
                    visible.forEach(call => this.collapsedContactCallIds.add(call.id));
                }
                this.renderContactCalls(shell);
            });
            shell.querySelector('[data-nexa-callrecords-search]')?.addEventListener('input', event => {
                this.contactCallFilter.query = event.currentTarget.value.trim().toLowerCase();
                this.renderContactCalls(shell);
            });
            shell.querySelector('[data-nexa-callrecords-filter-toggle]')?.addEventListener('click', event => {
                const filters = shell.querySelector('[data-nexa-callrecords-filters]');
                filters.hidden = !filters.hidden;
                event.currentTarget.setAttribute('aria-expanded', String(!filters.hidden));
            });
            shell.querySelector('[data-nexa-callrecord-period-toggle]')?.addEventListener('click', event => {
                event.stopPropagation();
                this.toggleCallFilterMenu(shell, 'period');
            });
            shell.querySelector('[data-nexa-callrecord-owner-toggle]')?.addEventListener('click', event => {
                event.stopPropagation();
                this.toggleCallFilterMenu(shell, 'owner');
            });
            shell.querySelectorAll('[data-nexa-callrecord-period]').forEach(button => {
                button.addEventListener('click', () => {
                    this.contactCallFilter.period = button.dataset.nexaCallrecordPeriod;
                    shell.querySelector('[data-nexa-callrecord-period-label]').textContent = button.textContent.trim();
                    this.closeCallFilterMenus(shell);
                    this.renderContactCalls(shell);
                });
            });
            shell.querySelector('[data-nexa-callrecord-owner-search]')?.addEventListener('input', event => {
                const term = event.currentTarget.value.trim().toLowerCase();
                shell.querySelectorAll('[data-nexa-callrecord-owner-option]').forEach(button => {
                    button.hidden = term && !button.dataset.nexaOwnerSearch.includes(term);
                });
            });
            shell.querySelector('[data-nexa-callrecord-status-toggle]')?.addEventListener('click', event => {
                event.stopPropagation();
                this.toggleCallFilterMenu(shell, 'status');
            });
            shell.querySelectorAll('[data-nexa-callrecord-status-option]').forEach(checkbox => {
                checkbox.addEventListener('change', () => {
                    if (checkbox.checked) this.contactCallFilter.statuses.add(checkbox.value);
                    else this.contactCallFilter.statuses.delete(checkbox.value);
                    this.updateCallFilterLabel(shell, 'status', this.contactCallFilter.statuses.size);
                    this.renderContactCalls(shell);
                });
            });

            if (this.callsFilterDocumentHandler) document.removeEventListener('click', this.callsFilterDocumentHandler);
            this.callsFilterDocumentHandler = event => {
                if (!event.target.closest('[data-nexa-callrecord-period-filter], [data-nexa-callrecord-owner-filter], [data-nexa-callrecord-status-filter]')) {
                    this.closeCallFilterMenus(shell);
                }
            };
            document.addEventListener('click', this.callsFilterDocumentHandler);
        }

        callFilterTypes() {
            return ['period', 'owner', 'status'];
        }

        toggleCallFilterMenu(shell, type) {
            const ownMenu = shell.querySelector(`[data-nexa-callrecord-${type}-menu]`);
            const ownToggle = shell.querySelector(`[data-nexa-callrecord-${type}-toggle]`);
            if (!ownMenu || !ownToggle) return;
            const opening = ownMenu.hidden;
            this.callFilterTypes().forEach(otherType => {
                if (otherType === type) return;
                shell.querySelector(`[data-nexa-callrecord-${otherType}-menu]`)?.setAttribute('hidden', '');
                shell.querySelector(`[data-nexa-callrecord-${otherType}-toggle]`)?.setAttribute('aria-expanded', 'false');
            });
            ownMenu.hidden = !opening;
            ownToggle.setAttribute('aria-expanded', String(opening));
            if (opening && type === 'owner') window.setTimeout(() => ownMenu.querySelector('input')?.focus(), 0);
        }

        closeCallFilterMenus(shell) {
            this.callFilterTypes().forEach(type => {
                shell.querySelector(`[data-nexa-callrecord-${type}-menu]`)?.setAttribute('hidden', '');
                shell.querySelector(`[data-nexa-callrecord-${type}-toggle]`)?.setAttribute('aria-expanded', 'false');
            });
        }

        updateCallFilterLabel(shell, type, count) {
            const labels = {status: 'Call outcome'};
            const label = shell.querySelector(`[data-nexa-callrecord-${type}-label]`);
            if (label) label.textContent = count > 0 ? `${labels[type]} (${count})` : labels[type];
        }

        async loadContactCallOwners(workspace) {
            const callAssignees = (this.contactCallRecords || []).map(call => ({
                id: call.assignedUserId,
                name: call.assignedUserName || 'Removed owner',
                isActive: true,
            })).filter(owner => owner.id);

            await this.ensureTenantOwnersLoaded();

            const owners = new Map();
            [...this.contactNoteTenantOwners, ...callAssignees].forEach(owner => {
                if (!owners.has(owner.id) || owner.isActive === false) owners.set(owner.id, owner);
            });
            this.contactCallOwners = [...owners.values()].sort((a, b) => a.name.localeCompare(b.name));
            const knownIds = new Set(this.contactNoteTenantOwners.map(owner => owner.id));
            this.deactivatedContactOwnerIds = new Set([
                ...(this.deactivatedContactOwnerIds || []),
                ...this.contactCallOwners
                    .filter(owner => owner.isActive === false || !knownIds.has(owner.id))
                    .map(owner => owner.id),
            ]);
            this.renderContactCallOwnerOptions(workspace);
        }

        renderContactCallOwnerOptions(workspace) {
            const container = workspace?.querySelector('[data-nexa-callrecord-owner-options]');
            if (!container) return;
            const owners = this.contactCallOwners || [];
            container.innerHTML = `
                <button type="button" data-nexa-callrecord-owner-option="all" data-nexa-owner-search="all owners">All owners</button>
                <button type="button" data-nexa-callrecord-owner-option="me" data-nexa-owner-search="me my activity">Me</button>
                <button type="button" data-nexa-callrecord-owner-option="deactivated" data-nexa-owner-search="deactivated removed owners">Deactivated and removed owners</button>
                ${owners.length ? '<p>Owners</p>' : ''}
                ${owners.map(owner => `<button type="button" data-nexa-callrecord-owner-option="${this.escape(owner.id)}" data-nexa-owner-search="${this.escape(owner.name.toLowerCase())}">${this.escape(owner.name)}${owner.isActive === false ? ' (deactivated)' : ''}</button>`).join('')}`;
            container.querySelectorAll('[data-nexa-callrecord-owner-option]').forEach(button => {
                button.addEventListener('click', () => {
                    const value = button.dataset.nexaCallrecordOwnerOption;
                    this.contactCallFilter.owner = value;
                    const labels = {
                        all: 'Activity assigned to',
                        me: 'Me',
                        deactivated: 'Deactivated and removed owners',
                    };
                    workspace.querySelector('[data-nexa-callrecord-owner-label]').textContent = labels[value] || button.textContent.trim();
                    const search = workspace.querySelector('[data-nexa-callrecord-owner-search]');
                    search.value = '';
                    container.querySelectorAll('[data-nexa-callrecord-owner-option]').forEach(option => option.hidden = false);
                    this.closeCallFilterMenus(workspace);
                    this.renderContactCalls(workspace);
                });
            });
        }

        async loadContactCalls(shell = null, newestId = null) {
            const workspace = shell || this.element.querySelector('[data-nexa-contact-workspace]');
            const list = workspace?.querySelector('[data-nexa-callrecord-list]');
            const count = workspace?.querySelector('[data-nexa-callrecord-count]');
            if (!list || !count) return;

            try {
                const payload = await Espo.Ajax.getRequest('Call', {
                    where: [
                        {type: 'linkedWith', attribute: 'contacts', value: [this.model.id]},
                    ],
                    select: 'id,name,status,dateStart,dateEnd,direction,assignedUserId,assignedUserName,description,createdAt,isPinned',
                    maxSize: 200,
                    orderBy: 'dateStart',
                    order: 'asc',
                });
                this.contactCallRecords = Array.isArray(payload?.list) ? payload.list : [];
                await Promise.all(this.contactCallRecords.map(async call => {
                    const model = await this.getModelFactory().create('Call');
                    model.set(call);
                    model.id = call.id;
                    const canEditModel = this.getAcl().checkModel(model, 'edit') === true;
                    call._editable = {
                        name: canEditModel && !!this.getAcl().checkField('Call', 'name', 'edit'),
                        description: canEditModel && !!this.getAcl().checkField('Call', 'description', 'edit'),
                        status: canEditModel && !!this.getAcl().checkField('Call', 'status', 'edit'),
                        direction: canEditModel && !!this.getAcl().checkField('Call', 'direction', 'edit'),
                        assignedUser: canEditModel && !!this.getAcl().checkField('Call', 'assignedUser', 'edit'),
                        when: canEditModel &&
                            !!this.getAcl().checkField('Call', 'dateStart', 'edit') &&
                            !!this.getAcl().checkField('Call', 'dateEnd', 'edit'),
                    };
                    call.canPin = canEditModel;
                    call.canDelete = this.getAcl().checkModel(model, 'delete') === true;
                }));
                this.knownContactCallIds = this.knownContactCallIds || new Set();
                this.contactCallRecords.forEach(call => {
                    if (this.knownContactCallIds.has(call.id)) return;
                    this.knownContactCallIds.add(call.id);
                    this.collapsedContactCallIds?.add(call.id);
                });
                this.renderContactCalls(workspace, newestId);
                this.renderContactOverviewInsights(workspace);
                this.loadContactCallCommentCounts(workspace);
                this.loadContactCallOwners(workspace);
            } catch (error) {
                list.innerHTML = `<div class="nexa-note-empty is-error"><span class="fas fa-exclamation-circle" aria-hidden="true"></span><div><strong>Calls unavailable</strong><p>Refresh the page to try loading them again.</p></div></div>`;
            }
        }

        async loadContactCallCommentCounts(workspace) {
            const ids = (this.contactCallRecords || []).map(call => call.id);
            this.contactCallCommentCounts = new Map();
            if (!ids.length) {
                this.renderContactCalls(workspace);
                return;
            }
            try {
                const payload = await Espo.Ajax.getRequest('Note', {
                    where: [
                        {type: 'equals', attribute: 'parentType', value: 'Call'},
                        {type: 'in', attribute: 'parentId', value: ids},
                        {type: 'equals', attribute: 'type', value: 'Post'},
                    ],
                    select: 'id,parentId',
                    maxSize: 200,
                });
                (payload?.list || []).forEach(note => {
                    const current = this.contactCallCommentCounts.get(note.parentId) || 0;
                    this.contactCallCommentCounts.set(note.parentId, current + 1);
                });
            } catch (error) {
                // Comment counts are a display enhancement only; failures are non-blocking.
            }
            this.renderContactCalls(workspace);
        }

        callIsOverdue(call) {
            if (!call.dateEnd || ['Held', 'Not Held'].includes(call.status)) return false;
            const date = this.contactNoteDate(call.dateEnd);
            return date ? date.getTime() < Date.now() : false;
        }

        filteredContactCalls() {
            const filter = this.contactCallFilter || {query: '', period: 'all', owner: 'all', statuses: new Set()};
            const currentUserId = this.getUser()?.id || this.getUser()?.get?.('id');
            return (this.contactCallRecords || []).filter(call => {
                const matchesQuery = !filter.query ||
                    `${call.name || ''} ${call.assignedUserName || ''}`.toLowerCase().includes(filter.query);
                const ownerId = call.assignedUserId || '';
                const matchesOwner = !filter.owner || filter.owner === 'all' ||
                    (filter.owner === 'me' && ownerId === currentUserId) ||
                    (filter.owner === 'deactivated' && this.deactivatedContactOwnerIds?.has(ownerId)) ||
                    ownerId === filter.owner;
                const matchesPeriod = this.contactNoteMatchesPeriod(call.dateStart || call.createdAt, filter.period);
                const matchesStatus = !filter.statuses?.size || filter.statuses.has(call.status);
                return matchesQuery && matchesOwner && matchesPeriod && matchesStatus;
            });
        }

        renderContactCalls(workspace = null, newestId = null) {
            workspace = workspace || this.element.querySelector('[data-nexa-contact-workspace]');
            const list = workspace?.querySelector('[data-nexa-callrecord-list]');
            const count = workspace?.querySelector('[data-nexa-callrecord-count]');
            if (!list || !count) return;
            this.cancelCallFieldEdit();

            const calls = this.filteredContactCalls();
            const groups = new Map();
            const pinned = calls.filter(call => call.isPinned);
            const chronological = calls.filter(call => !call.isPinned);
            if (pinned.length) groups.set('pinned', {label: 'Pinned', calls: pinned, pinned: true});
            chronological.forEach(call => {
                const date = this.contactNoteDate(call.dateStart);
                const key = date ? `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}` : 'unknown';
                const label = date
                    ? new Intl.DateTimeFormat(undefined, {month: 'long', year: 'numeric'}).format(date)
                    : 'No date';
                if (!groups.has(key)) groups.set(key, {label, calls: []});
                groups.get(key).calls.push(call);
            });

            count.textContent = `${calls.length} ${calls.length === 1 ? 'call' : 'calls'}`;
            list.innerHTML = calls.length
                ? [...groups.values()].map(group => `<section class="nexa-note-month${group.pinned ? ' is-pinned-group' : ''}"><h4>${group.pinned ? '<span class="fas fa-thumbtack" aria-hidden="true"></span>' : ''}${this.escape(group.label)}</h4>${group.calls.map(call => this.contactCallCard(call, call.id === newestId)).join('')}</section>`).join('')
                : `<div class="nexa-note-empty"><span class="fas fa-phone" aria-hidden="true"></span><div><strong>No matching calls</strong><p>Try another search, make a call, or schedule one.</p></div></div>`;

            const collapse = workspace.querySelector('[data-nexa-collapse-callrecords]');
            const allCollapsed = calls.length > 0 && calls.every(call => this.collapsedContactCallIds?.has(call.id));
            if (collapse) collapse.firstChild.textContent = allCollapsed ? 'Expand all ' : 'Collapse all ';

            this.bindContactCallList(list);
        }

        contactCallCard(call, isNewest = false, context = 'calls') {
            const startLabel = call.dateStart ? this.getDateTime().toDisplay(call.dateStart) : 'No date set';
            const endLabel = call.dateEnd ? this.getDateTime().toDisplay(call.dateEnd) : '';
            const timeRange = endLabel
                ? `${this.escape(startLabel)} &ndash; ${this.escape(endLabel)}`
                : this.escape(startLabel);
            const createdLabel = call.createdAt ? this.getDateTime().toDisplay(call.createdAt) : '';
            const collapsed = this.collapsedContactCallIds?.has(call.id);
            const overdue = this.callIsOverdue(call);
            const commentCount = this.contactCallCommentCounts?.get(call.id) || 0;
            const comments = this.contactCallComments?.get(call.id) || [];
            const commentsVisible = this.callCommentsVisibleIds?.has(call.id) === true;
            const nameText = `<span>${this.escape(call.name || 'Untitled call')}</span>`;
            const editorHost = `${context}-${call.id}`;
            const canComment = this.getAcl().checkScope('Note', 'create') === true;
            const canDelete = call.canDelete === true;
            const canPin = call.canPin === true;
            const deleteHelp = "You don't have permission to delete this call. Ask your admin to grant permission.";

            return `<article class="nexa-note-card${isNewest ? ' is-new' : ''}${collapsed ? ' is-collapsed' : ''}${call.isPinned ? ' is-pinned' : ''}" data-nexa-callrecord-id="${this.escape(call.id)}" data-nexa-callrecord-context="${this.escape(context)}">
                <header>
                    <button class="nexa-note-toggle" type="button" data-nexa-callrecord-toggle aria-expanded="${!collapsed}"><span class="fas fa-chevron-${collapsed ? 'right' : 'down'}" aria-hidden="true"></span>${call.isPinned ? '<span class="fas fa-thumbtack nexa-note-pinned-icon" aria-label="Pinned call"></span>' : ''}<span class="nexa-note-kind">Call</span><span>${this.escape(call.name || 'Untitled call')}</span>${overdue ? '<span class="nexa-note-overdue-badge" title="Overdue"><span class="fas fa-exclamation-circle" aria-hidden="true"></span></span>' : ''}${commentCount ? `<span class="nexa-note-comment-count"><span class="far fa-comment" aria-hidden="true"></span>${commentCount}</span>` : ''}</button>
                    <div class="nexa-note-header-meta"><div class="nexa-note-actions" data-nexa-callrecord-actions${collapsed ? ' hidden' : ''}><button type="button" class="nexa-note-actions-toggle" data-nexa-callrecord-actions-toggle aria-expanded="false">Actions <span class="fas fa-caret-down" aria-hidden="true"></span></button><div class="nexa-note-actions-menu" data-nexa-callrecord-actions-menu hidden><button type="button" data-nexa-callrecord-pin${canPin ? '' : ' disabled aria-disabled="true"'}><span class="fas fa-thumbtack" aria-hidden="true"></span>${call.isPinned ? 'Unpin' : 'Pin'}</button>${canDelete ? '<button type="button" class="is-danger" data-nexa-callrecord-delete><span class="far fa-trash-alt" aria-hidden="true"></span>Delete</button>' : `<span class="nexa-note-action-disabled" data-tooltip="${this.escape(deleteHelp)}" tabindex="0"><button type="button" class="is-danger" disabled aria-disabled="true"><span class="far fa-trash-alt" aria-hidden="true"></span>Delete</button></span>`}</div></div><time>${this.escape(createdLabel)}</time></div>
                </header>
                <p class="nexa-note-preview"${collapsed ? '' : ' hidden'}>${this.escape(call.name || 'Untitled call')} &middot; ${this.escape(startLabel)}</p>
                <div class="nexa-note-details" data-nexa-callrecord-details${collapsed ? ' hidden' : ''}>
                    ${this.callTitleRow(call, nameText)}
                    <dl class="nexa-activity-audit-details">
                        ${this.callInlineFactRow(call, 'when', 'Date & time', timeRange)}
                    </dl>
                    <div class="nexa-task-fact-line">
                        ${this.callInlineFactItem(call, 'status', 'Status', this.meetingStatusBadge(call.status))}
                        ${this.callInlineFactItem(call, 'direction', 'Direction', this.escape(call.direction || 'Outbound'))}
                        ${this.callInlineFactItem(call, 'assignedUser', 'Assigned to', this.escape(call.assignedUserName || 'Unassigned'))}
                    </div>
                    ${this.callNotesSection(call)}
                    <footer>
                        ${canComment ? '<button type="button" data-nexa-comment-toggle><span class="far fa-comment" aria-hidden="true"></span><span>Add comment</span></button>' : ''}
                        ${commentCount ? `<button type="button" class="nexa-task-reply-toggle" data-nexa-callrecord-comments-visibility-toggle>${commentsVisible ? 'Hide comments' : `Show comments (${commentCount})`}</button>` : '<span>0 comments</span>'}
                    </footer>
                    <div class="nexa-note-comments"${commentsVisible ? '' : ' hidden'}>${comments.map(comment => this.contactCallComment(comment, context)).join('')}</div>
                    <form class="nexa-note-comment-form" data-nexa-comment-form hidden>
                        <div class="nexa-native-rich-editor nexa-comment-editor" data-nexa-comment-editor-host="${this.escape(editorHost)}" aria-label="Comment"><div class="nexa-note-editor-loading"><span class="fas fa-circle-notch fa-spin" aria-hidden="true"></span><span>Loading editor</span></div></div>
                        <div><button type="button" class="btn btn-default btn-xs" data-nexa-comment-cancel>Cancel</button><button type="submit" class="btn btn-primary btn-xs">Comment</button></div>
                        <p role="alert" data-nexa-comment-error hidden></p>
                    </form>
                </div>
            </article>`;
        }

        callTitleRow(call, nameText) {
            if (!call._editable?.name) {
                return `<p class="nexa-task-title">${nameText}</p>`;
            }

            return `<div class="nexa-task-title nexa-inline-detail nexa-inline-detail--title" data-nexa-callrecord-inline-field data-field="name">
                <span class="nexa-inline-detail-display" data-nexa-inline-display>${nameText}</span>
                <button type="button" class="nexa-inline-detail-trigger" data-nexa-callrecord-inline-trigger aria-label="Edit call name"><span class="fas fa-pencil-alt" aria-hidden="true"></span></button>
            </div>`;
        }

        callNotesSection(call) {
            const bodyHtml = call.description ? this.formatNoteContent(call.description) : '<span class="nexa-record-empty">No description added</span>';
            if (!call._editable?.description) {
                return `<div class="nexa-task-notes-section"><h5 class="nexa-task-notes-heading">Description</h5><div class="nexa-note-body">${bodyHtml}</div></div>`;
            }

            return `<div class="nexa-task-notes-section nexa-inline-detail nexa-inline-detail--notes" data-nexa-callrecord-inline-field data-field="description">
                <div class="nexa-task-notes-heading-row"><h5 class="nexa-task-notes-heading">Description</h5><button type="button" class="nexa-inline-detail-trigger" data-nexa-callrecord-inline-trigger aria-label="Edit description"><span class="fas fa-pencil-alt" aria-hidden="true"></span></button></div>
                <div class="nexa-note-body" data-nexa-inline-display>${bodyHtml}</div>
            </div>`;
        }

        callInlineFactItem(call, field, label, displayHtml) {
            if (!call._editable?.[field]) {
                return `<span class="nexa-task-fact-item"><span class="nexa-task-fact-label">${this.escape(label)}</span><span class="nexa-task-fact-value-row"><strong>${displayHtml}</strong></span></span>`;
            }

            return `<span class="nexa-task-fact-item nexa-inline-detail nexa-inline-detail--compact" data-nexa-callrecord-inline-field data-field="${this.escape(field)}">
                <span class="nexa-task-fact-label">${this.escape(label)}</span>
                <span class="nexa-task-fact-value-row">
                    <strong class="nexa-inline-detail-display" data-nexa-inline-display>${displayHtml}</strong>
                    <button type="button" class="nexa-inline-detail-trigger" data-nexa-callrecord-inline-trigger aria-label="Edit ${this.escape(label)}"><span class="fas fa-pencil-alt" aria-hidden="true"></span></button>
                </span>
            </span>`;
        }

        callInlineFactRow(call, field, label, displayHtml) {
            if (!call._editable?.[field]) {
                return `<div><dt>${this.escape(label)}</dt><dd>${displayHtml}</dd></div>`;
            }

            return `<div class="nexa-inline-detail nexa-inline-detail--compact" data-nexa-callrecord-inline-field data-field="${this.escape(field)}">
                <dt>${this.escape(label)}</dt>
                <dd><span class="nexa-inline-detail-display" data-nexa-inline-display>${displayHtml}</span><button type="button" class="nexa-inline-detail-trigger" data-nexa-callrecord-inline-trigger aria-label="Edit ${this.escape(label)}"><span class="fas fa-pencil-alt" aria-hidden="true"></span></button></dd>
            </div>`;
        }

        contactCallComment(comment, context) {
            const author = comment.createdByName || 'Team member';
            const createdAt = comment.createdAt ? this.getDateTime().toDisplay(comment.createdAt) : '';
            const replies = comment.replies || [];
            const repliesVisible = this.callRepliesVisibleIds?.has(comment.id) === true;
            const replyEditorHost = `${context}-reply-${comment.id}`;
            const canComment = this.getAcl().checkScope('Note', 'create') === true;

            return `<div class="nexa-note-comment" data-nexa-callrecord-comment-id="${this.escape(comment.id)}">
                <div><strong>${this.escape(author)}</strong><time>${this.escape(createdAt)}</time></div>
                <p>${this.formatNoteContent(comment.content)}</p>
                <div class="nexa-task-comment-actions">
                    ${canComment ? '<button type="button" class="nexa-task-reply-toggle" data-nexa-callrecord-reply-toggle>Reply</button>' : ''}
                    ${replies.length ? `<button type="button" class="nexa-task-reply-toggle" data-nexa-callrecord-replies-visibility-toggle>${repliesVisible ? 'Hide replies' : `Show replies (${replies.length})`}</button>` : ''}
                </div>
                ${replies.length ? `<div class="nexa-task-comment-replies"${repliesVisible ? '' : ' hidden'}>${replies.map(reply => this.contactCallReply(reply)).join('')}</div>` : ''}
                <form class="nexa-note-comment-form nexa-task-reply-form" data-nexa-reply-form hidden>
                    <div class="nexa-native-rich-editor nexa-comment-editor" data-nexa-comment-editor-host="${this.escape(replyEditorHost)}" aria-label="Reply"><div class="nexa-note-editor-loading"><span class="fas fa-circle-notch fa-spin" aria-hidden="true"></span><span>Loading editor</span></div></div>
                    <div><button type="button" class="btn btn-default btn-xs" data-nexa-reply-cancel>Cancel</button><button type="submit" class="btn btn-primary btn-xs">Reply</button></div>
                    <p role="alert" data-nexa-reply-error hidden></p>
                </form>
            </div>`;
        }

        contactCallReply(reply) {
            const author = reply.createdByName || 'Team member';
            const createdAt = reply.createdAt ? this.getDateTime().toDisplay(reply.createdAt) : '';
            return `<div class="nexa-note-comment nexa-task-comment-reply"><div><strong>${this.escape(author)}</strong><time>${this.escape(createdAt)}</time></div><p>${this.formatNoteContent(reply.content)}</p></div>`;
        }

        async loadCallComments(callId, {force = false} = {}) {
            this.contactCallComments = this.contactCallComments || new Map();
            if (!force && this.contactCallComments.has(callId)) return;

            try {
                const payload = await Espo.Ajax.getRequest('Note', {
                    where: [
                        {type: 'equals', attribute: 'parentType', value: 'Call'},
                        {type: 'equals', attribute: 'parentId', value: callId},
                        {type: 'equals', attribute: 'type', value: 'Post'},
                    ],
                    select: 'id,post,createdAt,createdById,createdByName',
                    orderBy: 'createdAt',
                    order: 'asc',
                    maxSize: 200,
                });
                const records = payload?.list || [];
                const topLevel = [];
                const repliesByParent = new Map();

                records.forEach(record => {
                    const post = String(record.post || '');
                    const replyMatch = post.match(/^<!-- nexa-call-reply:([A-Za-z0-9_-]+) -->\s*\n?/);
                    if (replyMatch) {
                        const list = repliesByParent.get(replyMatch[1]) || [];
                        list.push({...record, content: post.replace(replyMatch[0], '')});
                        repliesByParent.set(replyMatch[1], list);
                        return;
                    }
                    topLevel.push({...record, content: post});
                });
                topLevel.forEach(comment => {
                    comment.replies = repliesByParent.get(comment.id) || [];
                });

                this.contactCallComments.set(callId, topLevel);
                this.contactCallCommentCounts = this.contactCallCommentCounts || new Map();
                this.contactCallCommentCounts.set(callId, records.length);
            } catch (error) {
                this.contactCallComments.set(callId, []);
            }
        }

        async createCallStreamNote(callId, post) {
            const note = await this.getModelFactory().create('Note');
            note.set({
                type: 'Post',
                post,
                parentType: 'Call',
                parentId: callId,
            });
            await note.save(null);
            return note;
        }

        async saveCallComment(callId, form) {
            if (form.dataset.saving === 'true') return;
            const context = form.closest('[data-nexa-callrecord-context]')?.dataset.nexaCallrecordContext || 'calls';
            const editor = this.contactCommentEditors?.get(`${context}-${callId}`);
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
                const note = await this.createCallStreamNote(callId, content);
                this.contactCallComments = this.contactCallComments || new Map();
                const comments = this.contactCallComments.get(callId) || [];
                comments.push({
                    id: note.id,
                    content,
                    replies: [],
                    createdAt: note.get('createdAt') || new Date().toISOString(),
                    createdById: this.getUser().id,
                    createdByName: this.getUser().get('name'),
                });
                this.contactCallComments.set(callId, comments);
                this.contactCallCommentCounts = this.contactCallCommentCounts || new Map();
                this.contactCallCommentCounts.set(callId, comments.length);
                this.clearContactCommentEditor(callId, context);
                form.hidden = true;
                this.callCommentsVisibleIds = this.callCommentsVisibleIds || new Set();
                this.callCommentsVisibleIds.add(callId);
                this.renderContactCalls();
            } catch (saveError) {
                error.textContent = 'The comment could not be saved.';
                error.hidden = false;
                form.dataset.saving = 'false';
                form.querySelector('button[type="submit"]').disabled = false;
            }
        }

        async saveCallReply(callId, commentId, form) {
            if (form.dataset.saving === 'true') return;
            const context = form.closest('[data-nexa-callrecord-context]')?.dataset.nexaCallrecordContext || 'calls';
            const editor = this.contactCommentEditors?.get(`${context}-reply-${commentId}`);
            if (!editor) return;
            editor.view.fetchToModel();
            const content = String(editor.model.get('post') || '').trim();
            const error = form.querySelector('[data-nexa-reply-error]');
            if (this.richTextIsEmpty(content)) {
                error.textContent = 'Enter a reply before saving.';
                error.hidden = false;
                return;
            }

            form.dataset.saving = 'true';
            form.querySelector('button[type="submit"]').disabled = true;
            error.hidden = true;
            try {
                await this.createCallStreamNote(callId, `<!-- nexa-call-reply:${commentId} -->\n${content}`);
                this.clearContactCommentEditor(`reply-${commentId}`, context);
                form.hidden = true;
                await this.loadCallComments(callId, {force: true});
                this.callCommentsVisibleIds = this.callCommentsVisibleIds || new Set();
                this.callCommentsVisibleIds.add(callId);
                this.callRepliesVisibleIds = this.callRepliesVisibleIds || new Set();
                this.callRepliesVisibleIds.add(commentId);
                this.renderContactCalls();
            } catch (saveError) {
                error.textContent = 'The reply could not be saved.';
                error.hidden = false;
                form.dataset.saving = 'false';
                form.querySelector('button[type="submit"]').disabled = false;
            }
        }

        bindContactCallList(list, context = 'calls') {
            list.querySelectorAll('[data-nexa-callrecord-inline-trigger]').forEach(button => {
                button.addEventListener('click', () => {
                    const row = button.closest('[data-nexa-callrecord-inline-field]');
                    const callId = row.closest('[data-nexa-callrecord-id]').dataset.nexaCallrecordId;
                    const field = row.dataset.field;
                    this.startCallFieldEdit(callId, field, row);
                });
            });
            list.querySelectorAll('[data-nexa-callrecord-toggle]').forEach(button => {
                button.addEventListener('click', () => {
                    const card = button.closest('[data-nexa-callrecord-id]');
                    const id = card.dataset.nexaCallrecordId;
                    const expanding = this.collapsedContactCallIds.has(id);
                    if (expanding) this.collapsedContactCallIds.delete(id);
                    else this.collapsedContactCallIds.add(id);
                    if (expanding && !this.contactCallComments?.has(id)) {
                        this.loadCallComments(id).then(() => this.renderContactCalls());
                    }
                    this.renderContactCalls();
                });
            });
            list.querySelectorAll('[data-nexa-comment-toggle]').forEach(button => {
                button.addEventListener('click', () => {
                    const card = button.closest('[data-nexa-callrecord-id]');
                    const form = card.querySelector('[data-nexa-comment-form]');
                    form.hidden = false;
                    this.mountContactCommentEditor(card.dataset.nexaCallrecordId, form, context);
                });
            });
            list.querySelectorAll('[data-nexa-comment-cancel]').forEach(button => {
                button.addEventListener('click', () => {
                    const form = button.closest('[data-nexa-comment-form]');
                    this.clearContactCommentEditor(form.closest('[data-nexa-callrecord-id]').dataset.nexaCallrecordId, context);
                    form.hidden = true;
                });
            });
            list.querySelectorAll('[data-nexa-comment-form]').forEach(form => {
                form.addEventListener('submit', event => {
                    event.preventDefault();
                    this.saveCallComment(form.closest('[data-nexa-callrecord-id]').dataset.nexaCallrecordId, form);
                });
            });
            list.querySelectorAll('[data-nexa-callrecord-reply-toggle]').forEach(button => {
                button.addEventListener('click', () => {
                    const commentEl = button.closest('[data-nexa-callrecord-comment-id]');
                    const commentId = commentEl.dataset.nexaCallrecordCommentId;
                    const form = commentEl.querySelector('[data-nexa-reply-form]');
                    form.hidden = false;
                    this.mountContactCommentEditor(`reply-${commentId}`, form, context);
                });
            });
            list.querySelectorAll('[data-nexa-reply-cancel]').forEach(button => {
                button.addEventListener('click', () => {
                    const form = button.closest('[data-nexa-reply-form]');
                    const commentId = form.closest('[data-nexa-callrecord-comment-id]').dataset.nexaCallrecordCommentId;
                    this.clearContactCommentEditor(`reply-${commentId}`, context);
                    form.hidden = true;
                });
            });
            list.querySelectorAll('[data-nexa-reply-form]').forEach(form => {
                form.addEventListener('submit', event => {
                    event.preventDefault();
                    const callId = form.closest('[data-nexa-callrecord-id]').dataset.nexaCallrecordId;
                    const commentId = form.closest('[data-nexa-callrecord-comment-id]').dataset.nexaCallrecordCommentId;
                    this.saveCallReply(callId, commentId, form);
                });
            });
            list.querySelectorAll('[data-nexa-callrecord-comments-visibility-toggle]').forEach(button => {
                button.addEventListener('click', () => {
                    const callId = button.closest('[data-nexa-callrecord-id]').dataset.nexaCallrecordId;
                    this.callCommentsVisibleIds = this.callCommentsVisibleIds || new Set();
                    if (this.callCommentsVisibleIds.has(callId)) this.callCommentsVisibleIds.delete(callId);
                    else this.callCommentsVisibleIds.add(callId);
                    this.renderContactCalls();
                });
            });
            list.querySelectorAll('[data-nexa-callrecord-replies-visibility-toggle]').forEach(button => {
                button.addEventListener('click', () => {
                    const commentId = button.closest('[data-nexa-callrecord-comment-id]').dataset.nexaCallrecordCommentId;
                    this.callRepliesVisibleIds = this.callRepliesVisibleIds || new Set();
                    if (this.callRepliesVisibleIds.has(commentId)) this.callRepliesVisibleIds.delete(commentId);
                    else this.callRepliesVisibleIds.add(commentId);
                    this.renderContactCalls();
                });
            });
            list.querySelectorAll('[data-nexa-callrecord-actions-toggle]').forEach(button => {
                button.addEventListener('click', event => {
                    event.stopPropagation();
                    const menu = button.parentElement.querySelector('[data-nexa-callrecord-actions-menu]');
                    const opening = menu.hidden;
                    list.querySelectorAll('[data-nexa-callrecord-actions-menu]').forEach(item => item.hidden = true);
                    list.querySelectorAll('[data-nexa-callrecord-actions-toggle]').forEach(item => item.setAttribute('aria-expanded', 'false'));
                    menu.hidden = !opening;
                    button.setAttribute('aria-expanded', String(opening));
                });
            });
            list.querySelectorAll('[data-nexa-callrecord-pin]').forEach(button => {
                button.addEventListener('click', () => {
                    const callId = button.closest('[data-nexa-callrecord-id]').dataset.nexaCallrecordId;
                    const call = (this.contactCallRecords || []).find(item => item.id === callId);
                    this.toggleCallPinned(callId, !call?.isPinned, button);
                });
            });
            list.querySelectorAll('[data-nexa-callrecord-delete]').forEach(button => {
                button.addEventListener('click', () => this.openCallDeleteDialog(button.closest('[data-nexa-callrecord-id]').dataset.nexaCallrecordId));
            });
            if (this.callActionsDocumentHandler) document.removeEventListener('click', this.callActionsDocumentHandler);
            this.callActionsDocumentHandler = event => {
                if (event.target.closest('[data-nexa-callrecord-actions]')) return;
                this.element.querySelectorAll('[data-nexa-callrecord-actions-menu]').forEach(menu => menu.hidden = true);
                this.element.querySelectorAll('[data-nexa-callrecord-actions-toggle]').forEach(button => button.setAttribute('aria-expanded', 'false'));
            };
            document.addEventListener('click', this.callActionsDocumentHandler);
        }

        async toggleCallPinned(callId, pinned, button) {
            if (!callId || button?.disabled || button?.dataset.saving === 'true') return;
            button.dataset.saving = 'true';
            button.disabled = true;
            try {
                const model = await this.getModelFactory().create('Call');
                model.id = callId;
                await model.save({isPinned: pinned}, {patch: true});
                const call = (this.contactCallRecords || []).find(item => item.id === callId);
                if (call) call.isPinned = pinned;
                this.renderContactCalls();
                Espo.Ui.success(pinned ? 'Call pinned' : 'Call unpinned');
            } catch (error) {
                button.disabled = false;
                button.dataset.saving = 'false';
                Espo.Ui.error('The call could not be updated. Check your access and try again.');
            }
        }

        openCallDeleteDialog(callId) {
            const call = (this.contactCallRecords || []).find(item => item.id === callId);
            if (!call?.canDelete) return;
            this.closeCallDeleteDialog();
            const overlay = document.createElement('div');
            overlay.className = 'nexa-note-delete-overlay';
            overlay.dataset.nexaCallrecordDeleteDialog = 'true';
            overlay.innerHTML = `<section class="nexa-note-delete-dialog" role="dialog" aria-modal="true" aria-labelledby="nexa-callrecord-delete-title"><header><div><p>Delete call</p><h2 id="nexa-callrecord-delete-title">Delete this call?</h2></div><button type="button" class="nexa-dialog-close" data-nexa-callrecord-delete-close aria-label="Close"><span class="fas fa-times" aria-hidden="true"></span></button></header><div class="nexa-note-delete-content"><p>This call will be removed from the contact timeline. This action cannot be undone from this page.</p><p class="nexa-note-delete-error" role="alert" hidden></p></div><footer><button type="button" class="btn btn-default" data-nexa-callrecord-delete-cancel>Cancel</button><button type="button" class="btn btn-danger" data-nexa-callrecord-delete-confirm><span class="far fa-trash-alt" aria-hidden="true"></span><span>Delete call</span></button></footer></section>`;
            document.body.append(overlay);
            this.callDeleteDialog = overlay;
            const close = () => this.closeCallDeleteDialog();
            overlay.querySelector('[data-nexa-callrecord-delete-close]').addEventListener('click', close);
            overlay.querySelector('[data-nexa-callrecord-delete-cancel]').addEventListener('click', close);
            overlay.addEventListener('click', event => { if (event.target === overlay) close(); });
            overlay.addEventListener('keydown', event => { if (event.key === 'Escape') close(); });
            overlay.querySelector('[data-nexa-callrecord-delete-confirm]').addEventListener('click', event => this.deleteContactCall(callId, event.currentTarget));
            window.setTimeout(() => overlay.querySelector('[data-nexa-callrecord-delete-cancel]')?.focus(), 0);
        }

        closeCallDeleteDialog() {
            this.callDeleteDialog?.remove();
            this.callDeleteDialog = null;
        }

        async deleteContactCall(callId, button) {
            if (!callId || button.dataset.saving === 'true') return;
            const error = this.callDeleteDialog?.querySelector('.nexa-note-delete-error');
            button.dataset.saving = 'true';
            button.disabled = true;
            button.classList.add('is-loading');
            try {
                await Espo.Ajax.deleteRequest(`Call/${encodeURIComponent(callId)}`);
                this.contactCallRecords = (this.contactCallRecords || []).filter(call => call.id !== callId);
                this.contactCallComments?.delete(callId);
                this.contactCallCommentCounts?.delete(callId);
                this.collapsedContactCallIds?.delete(callId);
                this.callCommentsVisibleIds?.delete(callId);
                this.closeCallDeleteDialog();
                this.renderContactCalls();
                Espo.Ui.success('Call deleted');
            } catch (deleteError) {
                if (error) {
                    error.textContent = 'The call could not be deleted. Check your permission and try again.';
                    error.hidden = false;
                }
                button.dataset.saving = 'false';
                button.disabled = false;
                button.classList.remove('is-loading');
            }
        }

        callFieldEditConfig(field) {
            const configs = {
                name: {mode: 'varchar'},
                description: {mode: 'wysiwyg'},
                status: {mode: 'enum'},
                direction: {mode: 'enum'},
                assignedUser: {mode: 'assignedUser'},
                when: {mode: 'when'},
            };
            return configs[field] || null;
        }

        async startCallFieldEdit(callId, field, row) {
            const call = (this.contactCallRecords || []).find(item => item.id === callId);
            const config = this.callFieldEditConfig(field);
            if (!call || !config || !call._editable?.[field]) {
                Espo.Ui.error(this.translate('Access denied'));
                return;
            }

            this.cancelCallFieldEdit();

            const display = row.querySelector('[data-nexa-inline-display]');
            const trigger = row.querySelector('[data-nexa-callrecord-inline-trigger]');
            const originalHtml = display.innerHTML;

            this.callInlineEditSeq = (this.callInlineEditSeq || 0) + 1;
            const key = `nexaCallField${this.callInlineEditSeq}`;
            const hostId = `nexa-callrecord-inline-host-${this.callInlineEditSeq}`;

            row.classList.add('is-editing');
            trigger.hidden = true;
            display.innerHTML = `<div class="nexa-task-inline-editor" data-nexa-callrecord-inline-host="${hostId}"></div>
                <div class="nexa-task-inline-actions">
                    <button type="button" class="btn btn-default btn-xs" data-nexa-callrecord-inline-cancel>Cancel</button>
                    <button type="button" class="btn btn-primary btn-xs" data-nexa-callrecord-inline-save>Save</button>
                </div>
                <p class="nexa-note-error" data-nexa-callrecord-inline-error role="alert" hidden></p>`;

            this.callFieldEditState = {
                callId, field, config, row, display, trigger, originalHtml, key,
                views: [], viewKeys: [key], model: null, saving: false,
            };

            display.querySelector('[data-nexa-callrecord-inline-cancel]').addEventListener('click', () => this.cancelCallFieldEdit());
            display.querySelector('[data-nexa-callrecord-inline-save]').addEventListener('click', () => this.saveCallFieldEdit());

            try {
                const model = await this.getModelFactory().create('Call');
                model.id = call.id;
                model.set(call);
                if (!this.callFieldEditState || this.callFieldEditState.key !== key) return;
                this.callFieldEditState.model = model;

                const hostSelector = `[data-nexa-callrecord-inline-host="${hostId}"]`;

                if (config.mode === 'when') {
                    await this.mountCallWhenEditor(model, key, hostSelector);
                } else if (config.mode === 'assignedUser') {
                    await this.ensureTenantOwnersLoaded();
                    if (!this.callFieldEditState || this.callFieldEditState.key !== key) return;
                    const assigneeHost = document.querySelector(hostSelector);
                    this.callFieldEditState.assigneePicker = this.mountAssigneePicker(assigneeHost, {
                        userId: model.get('assignedUserId'),
                        userName: model.get('assignedUserName'),
                        onChange: (id, name) => {
                            model.set({assignedUserId: id, assignedUserName: name});
                        },
                    });
                } else {
                    const viewPathByMode = {
                        enum: 'views/fields/enum',
                        varchar: 'views/fields/varchar',
                        wysiwyg: 'views/fields/wysiwyg',
                    };
                    const params = config.mode === 'wysiwyg' ? {height: 180, minHeight: 140} : undefined;
                    const view = await this.createView(key, viewPathByMode[config.mode], {
                        fullSelector: hostSelector, model, name: field, mode: 'edit',
                        ...(params ? {params} : {}),
                    });
                    await view.render();
                    if (!this.callFieldEditState || this.callFieldEditState.key !== key) return;
                    this.callFieldEditState.views = [view];
                }
            } catch (error) {
                this.cancelCallFieldEdit();
                Espo.Ui.error(this.translate('Error occurred'));
            }
        }

        async mountCallWhenEditor(model, key, hostSelector) {
            const host = document.querySelector(hostSelector);
            if (!host) return;

            host.innerHTML = `
                <div class="nexa-task-field" data-nexa-callrecord-inline-datestart></div>
                <div class="nexa-task-field" data-nexa-callrecord-inline-dateend></div>`;

            const startKey = `${key}Start`;
            const endKey = `${key}End`;
            this.callFieldEditState.viewKeys = [startKey, endKey];

            const startView = await this.createView(startKey, 'views/fields/datetime-optional', {
                fullSelector: `${hostSelector} [data-nexa-callrecord-inline-datestart]`,
                model, name: 'dateStart', mode: 'edit', params: {required: true},
            });
            await startView.render();
            if (!this.callFieldEditState || this.callFieldEditState.key !== key) return;

            const endView = await this.createView(endKey, 'views/fields/datetime-optional', {
                fullSelector: `${hostSelector} [data-nexa-callrecord-inline-dateend]`,
                model, name: 'dateEnd', mode: 'edit', params: {required: true},
            });
            await endView.render();
            if (!this.callFieldEditState || this.callFieldEditState.key !== key) return;

            this.callFieldEditState.views = [startView, endView];
        }

        async saveCallFieldEdit() {
            const state = this.callFieldEditState;
            if (!state || state.saving) return;

            const error = state.display.querySelector('[data-nexa-callrecord-inline-error]');
            error.hidden = true;

            state.views.forEach(view => view.fetchToModel());

            let attributes = null;

            if (state.field === 'name') {
                const name = String(state.model.get('name') || '').trim();
                if (!name) {
                    error.textContent = 'Enter a call name.';
                    error.hidden = false;
                    return;
                }
                attributes = {name};
            } else if (state.field === 'description') {
                attributes = {description: state.model.get('description') || ''};
            } else if (state.field === 'status') {
                attributes = {status: state.model.get('status')};
            } else if (state.field === 'direction') {
                attributes = {direction: state.model.get('direction')};
            } else if (state.field === 'assignedUser') {
                const assignedUserId = state.model.get('assignedUserId') || null;
                if (!assignedUserId) {
                    error.textContent = 'Choose someone to assign this call to.';
                    error.hidden = false;
                    return;
                }
                attributes = {assignedUserId, assignedUserName: state.model.get('assignedUserName') || null};
            } else if (state.field === 'when') {
                const dateStart = state.model.get('dateStart');
                const dateEnd = state.model.get('dateEnd');
                if (!dateStart || !dateEnd) {
                    error.textContent = 'Select a start and end date and time.';
                    error.hidden = false;
                    return;
                }
                if (new Date(dateEnd).getTime() < new Date(dateStart).getTime()) {
                    error.textContent = 'The end date and time must be after the start.';
                    error.hidden = false;
                    return;
                }
                attributes = {dateStart, dateEnd};
            }

            if (!attributes) return;

            state.saving = true;
            const saveButton = state.display.querySelector('[data-nexa-callrecord-inline-save]');
            saveButton.disabled = true;
            saveButton.classList.add('is-loading');

            try {
                await state.model.save(attributes, {patch: true});
                const call = (this.contactCallRecords || []).find(item => item.id === state.callId);
                if (call) Object.assign(call, attributes);
                this.cancelCallFieldEdit({skipRestore: true});
                Espo.Ui.success('Call updated');
                this.renderContactCalls();
            } catch (saveError) {
                error.textContent = 'The call could not be saved. Check your access and try again.';
                error.hidden = false;
                state.saving = false;
                saveButton.disabled = false;
                saveButton.classList.remove('is-loading');
            }
        }

        cancelCallFieldEdit({skipRestore = false} = {}) {
            const state = this.callFieldEditState;
            if (!state) return;
            (state.viewKeys || [state.key]).forEach(viewKey => {
                if (this.getView(viewKey)) this.clearView(viewKey);
            });
            state.assigneePicker?.destroy();
            if (!skipRestore && state.row?.isConnected) {
                state.display.innerHTML = state.originalHtml;
                state.row.classList.remove('is-editing');
                if (state.trigger) state.trigger.hidden = false;
            }
            this.callFieldEditState = null;
        }

        // ---- Email tab -----------------------------------------------
        // Deliberately view-only (no inline-edit/pin/delete like Calls) -
        // you can't edit content that's already sent, matching any real
        // email client. Comments still work "like other" tabs, but as a
        // flat list (no nested replies) to keep this scoped.

        // There's no push channel for a fetched reply landing in the
        // background (WebSocket is off in this install - useWebSocket is
        // false in config - and IMAP fetch runs on cron's own schedule
        // regardless), so a manual refresh was the only way to see one
        // arrive. Poll instead, while this Contact's workspace is open.
        startContactEmailPolling(shell) {
            this.stopContactEmailPolling();
            this.emailPollInterval = window.setInterval(() => this.pollContactEmails(shell), 20000);
        }

        stopContactEmailPolling() {
            if (this.emailPollInterval) {
                window.clearInterval(this.emailPollInterval);
                this.emailPollInterval = null;
            }
        }

        async pollContactEmails(shell) {
            if (!shell || !this.isRendered() || !document.body.contains(shell) || document.hidden) return;
            // Don't yank an in-progress comment away from someone mid-type.
            if (shell.querySelector('[data-nexa-comment-form]:not([hidden])')) return;

            const previousNewestId = this.contactEmailRecords?.[0]?.id || null;
            await this.loadContactEmails(shell);
            const currentNewestId = this.contactEmailRecords?.[0]?.id || null;
            if (currentNewestId && currentNewestId !== previousNewestId) {
                this.renderContactEmails(shell, currentNewestId);
            }
        }

        async loadContactEmails(shell = null, newestId = null) {
            const workspace = shell || this.element.querySelector('[data-nexa-contact-workspace]');
            const list = workspace?.querySelector('[data-nexa-emailrecord-list]');
            const count = workspace?.querySelector('[data-nexa-emailrecord-count]');
            if (!list || !count) return;

            try {
                // openContactEmailComposer() deliberately parents a new Email
                // to the contact's Account (not the Contact) whenever one
                // exists (see its b2cMode/!accountId branch) - so a B2B
                // contact's own sent emails never carry parentType=Contact at
                // all. Match both shapes; for the Account-parented ones,
                // keep only records that actually name this contact's own
                // address (an Account can have several contacts, and their
                // emails would otherwise all bleed into every contact's tab).
                const accountId = this.model.get('accountId');
                const orConditions = [
                    {type: 'and', value: [
                        {type: 'equals', attribute: 'parentType', value: 'Contact'},
                        {type: 'equals', attribute: 'parentId', value: this.model.id},
                    ]},
                ];
                if (accountId) {
                    orConditions.push({type: 'and', value: [
                        {type: 'equals', attribute: 'parentType', value: 'Account'},
                        {type: 'equals', attribute: 'parentId', value: accountId},
                    ]});
                }

                const payload = await Espo.Ajax.getRequest('Email', {
                    where: [{type: 'or', value: orConditions}],
                    select: 'id,name,subject,status,dateSent,sendAt,createdAt,fromString,fromName,to,cc,parentType,isRead,hasAttachment,bodyPlain,body,isPinned',
                    maxSize: 200,
                    orderBy: 'dateSent',
                    order: 'desc',
                });
                const contactEmailAddress = String(this.model.get('emailAddress') || '').trim().toLowerCase();
                this.contactEmailRecords = (Array.isArray(payload?.list) ? payload.list : []).filter(email => {
                    if (email.parentType === 'Contact') return true;
                    if (!contactEmailAddress) return false;
                    // Covers both directions: the contact's address is in
                    // to/cc for mail we sent them, but in from for a reply
                    // they send back - an Account-parented reply otherwise
                    // never matches, since its to/cc is our own mailbox.
                    const addresses = `${email.to || ''} ${email.cc || ''} ${email.fromString || ''}`.toLowerCase();
                    return addresses.includes(contactEmailAddress);
                });
                this.knownContactEmailIds = this.knownContactEmailIds || new Set();
                this.collapsedContactEmailIds = this.collapsedContactEmailIds || new Set();
                this.contactEmailRecords.forEach(email => {
                    if (this.knownContactEmailIds.has(email.id)) return;
                    this.knownContactEmailIds.add(email.id);
                    this.collapsedContactEmailIds.add(email.id);
                });
                await Promise.all(this.contactEmailRecords.map(async email => {
                    const model = await this.getModelFactory().create('Email');
                    model.set(email);
                    model.id = email.id;
                    email.canPin = this.getAcl().checkModel(model, 'edit') === true;
                    email.canDelete = this.getAcl().checkModel(model, 'delete') === true;
                }));
                this.renderContactEmails(workspace, newestId);
                this.renderContactActivities(workspace);
                this.loadContactEmailCommentCounts(workspace);
            } catch (error) {
                list.innerHTML = `<div class="nexa-note-empty is-error"><span class="fas fa-exclamation-circle" aria-hidden="true"></span><div><strong>Emails unavailable</strong><p>Refresh the page to try loading them again.</p></div></div>`;
            }
        }

        async loadContactEmailCommentCounts(workspace) {
            const ids = (this.contactEmailRecords || []).map(email => email.id);
            this.contactEmailCommentCounts = new Map();
            if (!ids.length) {
                this.renderContactEmails(workspace);
                return;
            }
            try {
                const payload = await Espo.Ajax.getRequest('Note', {
                    where: [
                        {type: 'equals', attribute: 'parentType', value: 'Email'},
                        {type: 'in', attribute: 'parentId', value: ids},
                        {type: 'equals', attribute: 'type', value: 'Post'},
                    ],
                    select: 'id,parentId',
                    maxSize: 200,
                });
                (payload?.list || []).forEach(note => {
                    const current = this.contactEmailCommentCounts.get(note.parentId) || 0;
                    this.contactEmailCommentCounts.set(note.parentId, current + 1);
                });
            } catch (error) {
                // Comment counts are a display enhancement only; failures are non-blocking.
            }
            this.renderContactEmails(workspace);
        }

        // The fallback chain that guarantees an email is never shown
        // undated: an actually-sent email always shows when it was sent; a
        // still-pending one shows when it's scheduled to go out (if the user
        // set one) or otherwise falls back to when the record was created -
        // covers Draft, Sending, Sent, Archived and Failed alike.
        emailDisplayInfo(email) {
            if (email.dateSent) return {rawDate: email.dateSent, prefix: 'Sent'};
            if (email.sendAt) return {rawDate: email.sendAt, prefix: 'Scheduled for'};
            return {rawDate: email.createdAt, prefix: 'Created'};
        }

        // Splits a reply's body into the new text the sender actually wrote
        // and the quoted original beneath it (e.g. "On <date>, X wrote: >
        // ..."), which every mail client appends inline - without this, a
        // one-line reply and its whole quoted thread render as one
        // undifferentiated block. `body` is not reliably HTML - EspoCRM
        // still populates it with raw text for plain-text mail (found the
        // hard way: a real fetched reply had is_html=0 but a non-empty
        // `body` identical to `bodyPlain`), so branch on what the content
        // actually looks like, not on which field happens to be populated.
        splitEmailBody(email) {
            const empty = '<span class="nexa-record-empty">No content</span>';
            const looksLikeHtml = value => /<[a-z][\s\S]*>/i.test(value || '');

            if (email.body && looksLikeHtml(email.body)) {
                try {
                    const doc = new DOMParser().parseFromString(email.body, 'text/html');
                    const container = doc.body;
                    let boundary = container.querySelector(
                        'blockquote, .gmail_quote, #divRplyFwdMsg, .moz-cite-prefix, .OutlookMessageHeader'
                    );
                    if (!boundary) {
                        const walker = doc.createTreeWalker(container, NodeFilter.SHOW_TEXT);
                        let node;
                        while ((node = walker.nextNode())) {
                            if (/^\s*On .{0,150}wrote:\s*$/.test(node.textContent)) {
                                boundary = node.parentElement;
                                break;
                            }
                        }
                    }

                    if (boundary) {
                        const main = document.createElement('div');
                        const quoted = document.createElement('div');
                        let reached = false;
                        let node = container.firstChild;
                        while (node) {
                            const next = node.nextSibling;
                            if (!reached && (node === boundary || node.contains?.(boundary))) reached = true;
                            (reached ? quoted : main).appendChild(node);
                            node = next;
                        }
                        if (quoted.childNodes.length) {
                            return {mainHtml: main.innerHTML.trim() || empty, quotedHtml: quoted.innerHTML.trim()};
                        }
                    }
                } catch (error) {
                    // Fall through to plain-text handling below.
                }
                return {mainHtml: email.body, quotedHtml: null};
            }

            const plainText = email.bodyPlain || email.body || '';
            if (plainText) return this.splitPlainTextQuote(plainText);

            return {mainHtml: empty, quotedHtml: null};
        }

        // Line-based quote stripping: a "> "-prefixed line is always quoted,
        // regardless of whether the new text sits above or below it (real
        // replies vary - found one where the new text came after the quote
        // block with no blank-line separator, which a single split-point
        // can't handle). The "On ... wrote:" header itself opens a quoted
        // run; the first subsequent line that isn't ">"-prefixed closes it.
        splitPlainTextQuote(text) {
            const empty = '<span class="nexa-record-empty">No content</span>';
            const lines = text.split(/\r\n|\r|\n/);
            const mainLines = [];
            const quotedLines = [];
            let inQuote = false;

            for (const line of lines) {
                const trimmed = line.trim();
                if (!inQuote && /^On .{0,150}wrote:$/.test(trimmed)) {
                    inQuote = true;
                    quotedLines.push(line);
                    continue;
                }
                if (inQuote && trimmed.startsWith('>')) {
                    quotedLines.push(line);
                    continue;
                }
                inQuote = false;
                mainLines.push(line);
            }

            const quoted = quotedLines.join('\n').trim();
            if (!quoted) return {mainHtml: this.escape(text).replace(/\n/g, '<br>'), quotedHtml: null};

            const main = mainLines.join('\n').trim();
            return {
                mainHtml: main ? this.escape(main).replace(/\n/g, '<br>') : empty,
                quotedHtml: this.escape(quoted).replace(/\n/g, '<br>'),
            };
        }

        contactEmailStatusBadge(status) {
            if (!status) return '<span class="nexa-record-empty">Not recorded</span>';
            // Fetched/incoming mail carries EspoCRM's native "Archived"/
            // "Received" status, which stock translates to "Imported" - a
            // technical label that reads as noise here. In a Contact's own
            // timeline any inbound message is their reply, so label and
            // color it distinctly from what we sent.
            const inbound = status === 'Archived' || status === 'Received';
            const statusClasses = {
                Draft: 'not-held', Sending: 'not-held', Sent: 'held', Archived: 'reply', Received: 'reply', Failed: 'danger',
            };
            const label = inbound ? 'Reply' : (this.getLanguage().translateOption(status, 'status', 'Email') || status);
            return `<span class="nexa-meeting-status nexa-meeting-status--${statusClasses[status] || 'other'}">${this.escape(label)}</span>`;
        }

        filteredContactEmails() {
            const filter = this.contactEmailFilter || {query: '', period: 'all', statuses: new Set()};
            return (this.contactEmailRecords || []).filter(email => {
                const subjectLine = email.subject || email.name || '';
                const matchesQuery = !filter.query ||
                    `${subjectLine} ${email.fromString || ''} ${email.to || ''}`.toLowerCase().includes(filter.query);
                const {rawDate} = this.emailDisplayInfo(email);
                const matchesPeriod = this.contactNoteMatchesPeriod(rawDate, filter.period);
                const matchesStatus = !filter.statuses?.size || filter.statuses.has(email.status);
                return matchesQuery && matchesPeriod && matchesStatus;
            });
        }

        renderContactEmails(workspace = null, newestId = null) {
            workspace = workspace || this.element.querySelector('[data-nexa-contact-workspace]');
            const list = workspace?.querySelector('[data-nexa-emailrecord-list]');
            const count = workspace?.querySelector('[data-nexa-emailrecord-count]');
            if (!list || !count) return;

            const emails = this.filteredContactEmails();
            const groups = new Map();
            emails.forEach(email => {
                const {rawDate} = this.emailDisplayInfo(email);
                const date = this.contactNoteDate(rawDate);
                const key = date ? `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}` : 'unknown';
                const label = date
                    ? new Intl.DateTimeFormat(undefined, {month: 'long', year: 'numeric'}).format(date)
                    : 'No date';
                if (!groups.has(key)) groups.set(key, {label, emails: []});
                groups.get(key).emails.push(email);
            });

            count.textContent = `${emails.length} ${emails.length === 1 ? 'email' : 'emails'}`;
            list.innerHTML = emails.length
                ? [...groups.values()].map(group => `<section class="nexa-note-month"><h4>${this.escape(group.label)}</h4>${group.emails.map(email => this.contactEmailCard(email, email.id === newestId)).join('')}</section>`).join('')
                : `<div class="nexa-note-empty"><span class="far fa-envelope" aria-hidden="true"></span><div><strong>No matching emails</strong><p>Try another search, or compose one to this contact.</p></div></div>`;

            const collapse = workspace.querySelector('[data-nexa-collapse-emailrecords]');
            const allCollapsed = emails.length > 0 && emails.every(email => this.collapsedContactEmailIds?.has(email.id));
            if (collapse) collapse.firstChild.textContent = allCollapsed ? 'Expand all ' : 'Collapse all ';

            this.bindContactEmailList(list);
        }

        contactEmailCard(email, isNewest = false, context = 'emails') {
            const subjectLine = email.subject || email.name || 'No subject';
            const {rawDate, prefix} = this.emailDisplayInfo(email);
            const dateLabel = rawDate ? `${prefix} ${this.getDateTime().toDisplay(rawDate)}` : 'No date recorded';
            const collapsed = this.collapsedContactEmailIds?.has(email.id);
            const commentCount = this.contactEmailCommentCounts?.get(email.id) || 0;
            const comments = this.contactEmailComments?.get(email.id) || [];
            const commentsVisible = this.emailCommentsVisibleIds?.has(email.id) === true;
            const editorHost = `${context}-${email.id}`;
            const canComment = this.getAcl().checkScope('Note', 'create') === true;
            const canReply = this.getAcl().checkScope('Email', 'create') === true;
            const {mainHtml, quotedHtml} = this.splitEmailBody(email);
            const inbound = email.status === 'Archived' || email.status === 'Received';
            const canPin = email.canPin === true;
            const canDelete = email.canDelete === true;
            const deleteHelp = "You don't have permission to delete this email. Ask your admin to grant permission.";
            const actionsHtml = `<div class="nexa-note-actions" data-nexa-emailrecord-actions${collapsed ? ' hidden' : ''}><button type="button" class="nexa-note-actions-toggle" data-nexa-emailrecord-actions-toggle aria-expanded="false">Actions <span class="fas fa-caret-down" aria-hidden="true"></span></button><div class="nexa-note-actions-menu" data-nexa-emailrecord-actions-menu hidden><button type="button" data-nexa-emailrecord-pin${canPin ? '' : ' disabled aria-disabled="true"'}><span class="fas fa-thumbtack" aria-hidden="true"></span>${email.isPinned ? 'Unpin' : 'Pin'}</button>${canDelete ? '<button type="button" class="is-danger" data-nexa-emailrecord-delete><span class="far fa-trash-alt" aria-hidden="true"></span>Delete</button>' : `<span class="nexa-note-action-disabled" data-tooltip="${this.escape(deleteHelp)}" tabindex="0"><button type="button" class="is-danger" disabled aria-disabled="true"><span class="far fa-trash-alt" aria-hidden="true"></span>Delete</button></span>`}</div></div>`;

            return `<article class="nexa-note-card${isNewest ? ' is-new' : ''}${collapsed ? ' is-collapsed' : ''}${inbound ? ' is-inbound' : ''}${email.isPinned ? ' is-pinned' : ''}" data-nexa-emailrecord-id="${this.escape(email.id)}" data-nexa-emailrecord-context="${this.escape(context)}">
                <header>
                    <button class="nexa-note-toggle" type="button" data-nexa-emailrecord-toggle aria-expanded="${!collapsed}"><span class="fas fa-chevron-${collapsed ? 'right' : 'down'}" aria-hidden="true"></span>${email.isPinned ? '<span class="fas fa-thumbtack nexa-note-pinned-icon" aria-label="Pinned email"></span>' : ''}<span class="nexa-note-kind">Email</span><span>${this.escape(subjectLine)}</span>${commentCount ? `<span class="nexa-note-comment-count"><span class="far fa-comment" aria-hidden="true"></span>${commentCount}</span>` : ''}</button>
                    <div class="nexa-note-header-meta">${actionsHtml}${this.contactEmailStatusBadge(email.status)}<time>${this.escape(dateLabel)}</time></div>
                </header>
                <p class="nexa-note-preview"${collapsed ? '' : ' hidden'}>${this.escape(subjectLine)} &middot; ${this.escape(dateLabel)}</p>
                <div class="nexa-note-details" data-nexa-emailrecord-details${collapsed ? ' hidden' : ''}>
                    <div class="nexa-task-fact-line">
                        <span class="nexa-task-fact-item"><span class="nexa-task-fact-label">From</span><span class="nexa-task-fact-value-row"><strong>${this.escape(email.fromString || email.fromName || 'Unknown')}</strong></span></span>
                        <span class="nexa-task-fact-item"><span class="nexa-task-fact-label">To</span><span class="nexa-task-fact-value-row"><strong>${this.escape(email.to || 'Unknown')}</strong></span></span>
                    </div>
                    <div class="nexa-task-notes-section">
                        <h5 class="nexa-task-notes-heading">Message</h5>
                        <div class="nexa-note-body">${mainHtml}</div>
                        ${quotedHtml ? `<button type="button" class="nexa-email-quote-toggle" data-nexa-emailrecord-quote-toggle aria-expanded="false"><span class="fas fa-ellipsis-h" aria-hidden="true"></span><span>Show quoted text</span></button><div class="nexa-note-body nexa-email-quoted-body" data-nexa-emailrecord-quoted hidden>${quotedHtml}</div>` : ''}
                    </div>
                    <footer>
                        ${canReply ? '<button type="button" data-nexa-emailrecord-reply><span class="fas fa-reply" aria-hidden="true"></span><span>Reply</span></button>' : ''}
                        ${canComment ? '<button type="button" data-nexa-comment-toggle><span class="far fa-comment" aria-hidden="true"></span><span>Add comment</span></button>' : ''}
                        ${commentCount ? `<button type="button" class="nexa-task-reply-toggle" data-nexa-emailrecord-comments-visibility-toggle>${commentsVisible ? 'Hide comments' : `Show comments (${commentCount})`}</button>` : '<span>0 comments</span>'}
                    </footer>
                    <div class="nexa-note-comments"${commentsVisible ? '' : ' hidden'}>${comments.map(comment => this.contactEmailComment(comment)).join('')}</div>
                    <form class="nexa-note-comment-form" data-nexa-comment-form hidden>
                        <div class="nexa-native-rich-editor nexa-comment-editor" data-nexa-comment-editor-host="${this.escape(editorHost)}" aria-label="Comment"><div class="nexa-note-editor-loading"><span class="fas fa-circle-notch fa-spin" aria-hidden="true"></span><span>Loading editor</span></div></div>
                        <div><button type="button" class="btn btn-default btn-xs" data-nexa-comment-cancel>Cancel</button><button type="submit" class="btn btn-primary btn-xs">Comment</button></div>
                        <p role="alert" data-nexa-comment-error hidden></p>
                    </form>
                </div>
            </article>`;
        }

        contactEmailComment(comment) {
            const author = comment.createdByName || 'Team member';
            const createdAt = comment.createdAt ? this.getDateTime().toDisplay(comment.createdAt) : '';
            return `<div class="nexa-note-comment" data-nexa-emailrecord-comment-id="${this.escape(comment.id)}">
                <div><strong>${this.escape(author)}</strong><time>${this.escape(createdAt)}</time></div>
                <p>${this.formatNoteContent(comment.content)}</p>
            </div>`;
        }

        async loadEmailComments(emailId, {force = false} = {}) {
            this.contactEmailComments = this.contactEmailComments || new Map();
            if (!force && this.contactEmailComments.has(emailId)) return;

            try {
                const payload = await Espo.Ajax.getRequest('Note', {
                    where: [
                        {type: 'equals', attribute: 'parentType', value: 'Email'},
                        {type: 'equals', attribute: 'parentId', value: emailId},
                        {type: 'equals', attribute: 'type', value: 'Post'},
                    ],
                    select: 'id,post,createdAt,createdById,createdByName',
                    orderBy: 'createdAt',
                    order: 'asc',
                    maxSize: 200,
                });
                const records = (payload?.list || []).map(record => ({...record, content: record.post}));
                this.contactEmailComments.set(emailId, records);
                this.contactEmailCommentCounts = this.contactEmailCommentCounts || new Map();
                this.contactEmailCommentCounts.set(emailId, records.length);
            } catch (error) {
                this.contactEmailComments.set(emailId, []);
            }
        }

        async createEmailStreamNote(emailId, post) {
            const note = await this.getModelFactory().create('Note');
            note.set({type: 'Post', post, parentType: 'Email', parentId: emailId});
            await note.save(null);
            return note;
        }

        async saveEmailComment(emailId, form) {
            if (form.dataset.saving === 'true') return;
            const context = form.closest('[data-nexa-emailrecord-context]')?.dataset.nexaEmailrecordContext || 'emails';
            const editor = this.contactCommentEditors?.get(`${context}-${emailId}`);
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
                const note = await this.createEmailStreamNote(emailId, content);
                this.contactEmailComments = this.contactEmailComments || new Map();
                const comments = this.contactEmailComments.get(emailId) || [];
                comments.push({
                    id: note.id,
                    content,
                    createdAt: note.get('createdAt') || new Date().toISOString(),
                    createdById: this.getUser().id,
                    createdByName: this.getUser().get('name'),
                });
                this.contactEmailComments.set(emailId, comments);
                this.contactEmailCommentCounts = this.contactEmailCommentCounts || new Map();
                this.contactEmailCommentCounts.set(emailId, comments.length);
                this.clearContactCommentEditor(emailId, context);
                form.hidden = true;
                this.emailCommentsVisibleIds = this.emailCommentsVisibleIds || new Set();
                this.emailCommentsVisibleIds.add(emailId);
                this.renderContactEmails();
            } catch (saveError) {
                error.textContent = 'The comment could not be saved.';
                error.hidden = false;
                form.dataset.saving = 'false';
                form.querySelector('button[type="submit"]').disabled = false;
            }
        }

        bindContactEmailList(list, context = 'emails') {
            list.querySelectorAll('[data-nexa-emailrecord-quote-toggle]').forEach(button => {
                button.addEventListener('click', () => {
                    const quoted = button.nextElementSibling;
                    const expanded = button.getAttribute('aria-expanded') === 'true';
                    button.setAttribute('aria-expanded', String(!expanded));
                    button.querySelector('span:last-child').textContent = expanded ? 'Show quoted text' : 'Hide quoted text';
                    if (quoted) quoted.hidden = expanded;
                });
            });
            list.querySelectorAll('[data-nexa-emailrecord-toggle]').forEach(button => {
                button.addEventListener('click', () => {
                    const card = button.closest('[data-nexa-emailrecord-id]');
                    const id = card.dataset.nexaEmailrecordId;
                    const expanding = this.collapsedContactEmailIds.has(id);
                    if (expanding) this.collapsedContactEmailIds.delete(id);
                    else this.collapsedContactEmailIds.add(id);
                    if (expanding && !this.contactEmailComments?.has(id)) {
                        this.loadEmailComments(id).then(() => this.renderContactEmails());
                    }
                    this.renderContactEmails();
                });
            });
            list.querySelectorAll('[data-nexa-emailrecord-reply]').forEach(button => {
                button.addEventListener('click', () => {
                    const id = button.closest('[data-nexa-emailrecord-id]').dataset.nexaEmailrecordId;
                    const email = (this.contactEmailRecords || []).find(item => item.id === id);
                    if (email) this.replyToContactEmail(email);
                });
            });
            list.querySelectorAll('[data-nexa-comment-toggle]').forEach(button => {
                button.addEventListener('click', () => {
                    const card = button.closest('[data-nexa-emailrecord-id]');
                    const form = card.querySelector('[data-nexa-comment-form]');
                    form.hidden = false;
                    this.mountContactCommentEditor(card.dataset.nexaEmailrecordId, form, context);
                });
            });
            list.querySelectorAll('[data-nexa-comment-cancel]').forEach(button => {
                button.addEventListener('click', () => {
                    const form = button.closest('[data-nexa-comment-form]');
                    this.clearContactCommentEditor(form.closest('[data-nexa-emailrecord-id]').dataset.nexaEmailrecordId, context);
                    form.hidden = true;
                });
            });
            list.querySelectorAll('[data-nexa-comment-form]').forEach(form => {
                form.addEventListener('submit', event => {
                    event.preventDefault();
                    this.saveEmailComment(form.closest('[data-nexa-emailrecord-id]').dataset.nexaEmailrecordId, form);
                });
            });
            list.querySelectorAll('[data-nexa-emailrecord-comments-visibility-toggle]').forEach(button => {
                button.addEventListener('click', () => {
                    const emailId = button.closest('[data-nexa-emailrecord-id]').dataset.nexaEmailrecordId;
                    this.emailCommentsVisibleIds = this.emailCommentsVisibleIds || new Set();
                    if (this.emailCommentsVisibleIds.has(emailId)) this.emailCommentsVisibleIds.delete(emailId);
                    else this.emailCommentsVisibleIds.add(emailId);
                    this.renderContactEmails();
                });
            });
            list.querySelectorAll('[data-nexa-emailrecord-actions-toggle]').forEach(button => {
                button.addEventListener('click', event => {
                    event.stopPropagation();
                    const menu = button.parentElement.querySelector('[data-nexa-emailrecord-actions-menu]');
                    const opening = menu.hidden;
                    list.querySelectorAll('[data-nexa-emailrecord-actions-menu]').forEach(item => item.hidden = true);
                    list.querySelectorAll('[data-nexa-emailrecord-actions-toggle]').forEach(item => item.setAttribute('aria-expanded', 'false'));
                    menu.hidden = !opening;
                    button.setAttribute('aria-expanded', String(opening));
                });
            });
            list.querySelectorAll('[data-nexa-emailrecord-pin]').forEach(button => {
                button.addEventListener('click', () => {
                    const emailId = button.closest('[data-nexa-emailrecord-id]').dataset.nexaEmailrecordId;
                    const email = (this.contactEmailRecords || []).find(item => item.id === emailId);
                    this.toggleEmailPinned(emailId, !email?.isPinned, button);
                });
            });
            list.querySelectorAll('[data-nexa-emailrecord-delete]').forEach(button => {
                button.addEventListener('click', () => this.openEmailDeleteDialog(button.closest('[data-nexa-emailrecord-id]').dataset.nexaEmailrecordId));
            });
            if (this.emailActionsDocumentHandler) document.removeEventListener('click', this.emailActionsDocumentHandler);
            this.emailActionsDocumentHandler = event => {
                if (event.target.closest('[data-nexa-emailrecord-actions]')) return;
                this.element.querySelectorAll('[data-nexa-emailrecord-actions-menu]').forEach(menu => menu.hidden = true);
                this.element.querySelectorAll('[data-nexa-emailrecord-actions-toggle]').forEach(button => button.setAttribute('aria-expanded', 'false'));
            };
            document.addEventListener('click', this.emailActionsDocumentHandler);
        }

        async toggleEmailPinned(emailId, pinned, button) {
            if (!emailId || button?.disabled || button?.dataset.saving === 'true') return;
            button.dataset.saving = 'true';
            button.disabled = true;
            try {
                const model = await this.getModelFactory().create('Email');
                model.id = emailId;
                await model.save({isPinned: pinned}, {patch: true});
                const email = (this.contactEmailRecords || []).find(item => item.id === emailId);
                if (email) email.isPinned = pinned;
                this.renderContactEmails();
                this.renderContactActivities();
                Espo.Ui.success(pinned ? 'Email pinned' : 'Email unpinned');
            } catch (error) {
                button.disabled = false;
                button.dataset.saving = 'false';
                Espo.Ui.error('The email could not be updated. Check your access and try again.');
            }
        }

        openEmailDeleteDialog(emailId) {
            const email = (this.contactEmailRecords || []).find(item => item.id === emailId);
            if (!email?.canDelete) return;
            this.closeEmailDeleteDialog();
            const overlay = document.createElement('div');
            overlay.className = 'nexa-note-delete-overlay';
            overlay.dataset.nexaEmailDeleteDialog = 'true';
            overlay.innerHTML = `<section class="nexa-note-delete-dialog" role="dialog" aria-modal="true" aria-labelledby="nexa-email-delete-title"><header><div><p>Delete email</p><h2 id="nexa-email-delete-title">Delete this email?</h2></div><button type="button" class="nexa-dialog-close" data-nexa-email-delete-close aria-label="Close"><span class="fas fa-times" aria-hidden="true"></span></button></header><div class="nexa-note-delete-content"><p>This email will be removed from the contact timeline. This action cannot be undone from this page.</p><p class="nexa-note-delete-error" role="alert" hidden></p></div><footer><button type="button" class="btn btn-default" data-nexa-email-delete-cancel>Cancel</button><button type="button" class="btn btn-danger" data-nexa-email-delete-confirm><span class="far fa-trash-alt" aria-hidden="true"></span><span>Delete email</span></button></footer></section>`;
            document.body.append(overlay);
            this.emailDeleteDialog = overlay;
            const close = () => this.closeEmailDeleteDialog();
            overlay.querySelector('[data-nexa-email-delete-close]').addEventListener('click', close);
            overlay.querySelector('[data-nexa-email-delete-cancel]').addEventListener('click', close);
            overlay.addEventListener('click', event => { if (event.target === overlay) close(); });
            overlay.addEventListener('keydown', event => { if (event.key === 'Escape') close(); });
            overlay.querySelector('[data-nexa-email-delete-confirm]').addEventListener('click', event => this.deleteContactEmail(emailId, event.currentTarget));
            window.setTimeout(() => overlay.querySelector('[data-nexa-email-delete-cancel]')?.focus(), 0);
        }

        closeEmailDeleteDialog() {
            this.emailDeleteDialog?.remove();
            this.emailDeleteDialog = null;
        }

        async deleteContactEmail(emailId, button) {
            if (!emailId || button.dataset.saving === 'true') return;
            const error = this.emailDeleteDialog?.querySelector('.nexa-note-delete-error');
            button.dataset.saving = 'true';
            button.disabled = true;
            button.classList.add('is-loading');
            try {
                await Espo.Ajax.deleteRequest(`Email/${encodeURIComponent(emailId)}`);
                this.contactEmailRecords = (this.contactEmailRecords || []).filter(email => email.id !== emailId);
                this.contactEmailComments?.delete(emailId);
                this.collapsedContactEmailIds?.delete(emailId);
                this.closeEmailDeleteDialog();
                this.renderContactEmails();
                this.renderContactActivities();
                Espo.Ui.success('Email deleted');
            } catch (deleteError) {
                if (error) {
                    error.textContent = 'The email could not be deleted. Check your permission and try again.';
                    error.hidden = false;
                }
                button.dataset.saving = 'false';
                button.disabled = false;
                button.classList.remove('is-loading');
            }
        }

        emailFilterTypes() {
            return ['period', 'status'];
        }

        toggleEmailFilterMenu(shell, type) {
            const ownMenu = shell.querySelector(`[data-nexa-emailrecord-${type}-menu]`);
            const ownToggle = shell.querySelector(`[data-nexa-emailrecord-${type}-toggle]`);
            if (!ownMenu || !ownToggle) return;
            const opening = ownMenu.hidden;
            this.emailFilterTypes().forEach(otherType => {
                if (otherType === type) return;
                shell.querySelector(`[data-nexa-emailrecord-${otherType}-menu]`)?.setAttribute('hidden', '');
                shell.querySelector(`[data-nexa-emailrecord-${otherType}-toggle]`)?.setAttribute('aria-expanded', 'false');
            });
            ownMenu.hidden = !opening;
            ownToggle.setAttribute('aria-expanded', String(opening));
        }

        closeEmailFilterMenus(shell) {
            this.emailFilterTypes().forEach(type => {
                shell.querySelector(`[data-nexa-emailrecord-${type}-menu]`)?.setAttribute('hidden', '');
                shell.querySelector(`[data-nexa-emailrecord-${type}-toggle]`)?.setAttribute('aria-expanded', 'false');
            });
        }

        bindContactEmailsWorkspace(shell) {
            this.contactEmailFilter = this.contactEmailFilter || {query: '', period: 'all', statuses: new Set()};
            this.collapsedContactEmailIds = this.collapsedContactEmailIds || new Set();

            shell.querySelector('[data-nexa-create-emailrecord]')?.addEventListener('click', () => this.openContactEmailComposer());
            shell.querySelector('[data-nexa-manage-inbox]')?.addEventListener('click', () => this.openConnectInboxModal());
            this.refreshManageInboxLabel(shell);
            shell.querySelector('[data-nexa-collapse-emailrecords]')?.addEventListener('click', () => {
                const visible = this.filteredContactEmails();
                const allCollapsed = visible.length > 0 && visible.every(email => this.collapsedContactEmailIds.has(email.id));
                if (allCollapsed) visible.forEach(email => this.collapsedContactEmailIds.delete(email.id));
                else visible.forEach(email => this.collapsedContactEmailIds.add(email.id));
                this.renderContactEmails(shell);
            });
            shell.querySelector('[data-nexa-emailrecords-search]')?.addEventListener('input', event => {
                this.contactEmailFilter.query = event.currentTarget.value.trim().toLowerCase();
                this.renderContactEmails(shell);
            });
            shell.querySelector('[data-nexa-emailrecords-filter-toggle]')?.addEventListener('click', event => {
                const filters = shell.querySelector('[data-nexa-emailrecords-filters]');
                filters.hidden = !filters.hidden;
                event.currentTarget.setAttribute('aria-expanded', String(!filters.hidden));
            });
            shell.querySelector('[data-nexa-emailrecord-period-toggle]')?.addEventListener('click', event => {
                event.stopPropagation();
                this.toggleEmailFilterMenu(shell, 'period');
            });
            shell.querySelectorAll('[data-nexa-emailrecord-period]').forEach(button => {
                button.addEventListener('click', () => {
                    this.contactEmailFilter.period = button.dataset.nexaEmailrecordPeriod;
                    shell.querySelector('[data-nexa-emailrecord-period-label]').textContent = button.textContent.trim();
                    this.closeEmailFilterMenus(shell);
                    this.renderContactEmails(shell);
                });
            });
            shell.querySelector('[data-nexa-emailrecord-status-toggle]')?.addEventListener('click', event => {
                event.stopPropagation();
                this.toggleEmailFilterMenu(shell, 'status');
            });
            shell.querySelectorAll('[data-nexa-emailrecord-status-option]').forEach(checkbox => {
                checkbox.addEventListener('change', () => {
                    if (checkbox.checked) this.contactEmailFilter.statuses.add(checkbox.value);
                    else this.contactEmailFilter.statuses.delete(checkbox.value);
                    const labelEl = shell.querySelector('[data-nexa-emailrecord-status-label]');
                    const count = this.contactEmailFilter.statuses.size;
                    if (labelEl) labelEl.textContent = count > 0 ? `Email status (${count})` : 'Email status';
                    this.renderContactEmails(shell);
                });
            });

            if (this.emailsFilterDocumentHandler) document.removeEventListener('click', this.emailsFilterDocumentHandler);
            this.emailsFilterDocumentHandler = event => {
                if (!event.target.closest('[data-nexa-emailrecord-period-filter], [data-nexa-emailrecord-status-filter]')) {
                    this.closeEmailFilterMenus(shell);
                }
            };
            document.addEventListener('click', this.emailsFilterDocumentHandler);
        }
    };
});
