define('custom:views/marketing-email/workspace', ['view'], Dep => class extends Dep {
    template = 'custom:marketing-email/workspace';
    events = {
        'click [data-action="new-email"]': 'newEmail',
        'click [data-action="edit-email"]': 'editEmail',
        'click [data-action="close-editor"]': 'closeEditor',
        'click [data-action="reload"]': 'load',
        'click [data-action="transition"]': 'transition',
        'click [data-action="schedule-email"]': 'scheduleEmail',
        'click [data-action="test-email"]': 'chooseTestContact',
        'click [data-action="single-send"]': 'chooseSingleSendContact',
        'click [data-action="close-single-send"]': 'closeSingleSend',
        'submit [data-single-send-form]': 'submitSingleSend',
        'change [name="deliveryMode"]': 'updateDeliveryMode',
        'click [data-action="content-mode"]': 'toggleContentMode',
        'click [data-action="open-block-designer"]': 'openBlockDesigner',
        'click [data-action="conditional-content"]': 'openConditionalContent',
        'click [data-action="close-conditional-content"]': 'closeConditionalContent',
        'change [data-conditional-form] [name="field"], change [data-conditional-form] [name="operator"]': 'updateConditionalValue',
        'submit [data-conditional-form]': 'insertConditionalContent',
        'click [data-action="close-block-designer"]': 'closeBlockDesigner',
        'click [data-action="apply-block-design"]': 'applyBlockDesign',
        'click [data-action="choose-attachment"]': 'chooseAttachment',
        'click [data-action="remove-attachment"]': 'removeAttachment',
        'click [data-action="control-delivery"]': 'controlDelivery',
        'click [data-action="review-submissions"]': 'openSubmissionReview',
        'click [data-action="close-submission-review"]': 'closeSubmissionReview',
        'submit [data-submission-review-form]': 'resolveSubmission',
        'change [data-submission-review-form] [name="decision"]': 'updateReviewDecision',
        'click [data-action="version-history"]': 'openVersionHistory',
        'click [data-action="close-history"]': 'closeVersionHistory',
        'change [data-history-version]': 'loadHistoryVersion',
        'click [data-action="restore-version"]': 'restoreHistoryVersion',
        'click [data-action="email-results"]': 'openResults',
        'click [data-action="close-results"]': 'closeResults',
        'input [data-recipient-search]': 'searchDeliveryRecipients',
        'change [data-recipient-status]': 'searchDeliveryRecipients',
        'click [data-action="delivery-setup"]': 'openDelivery',
        'click [data-action="close-delivery"]': 'closeDelivery',
        'click [data-action="verify-domain"]': 'verifyDomain',
        'click [data-action="copy-callback"]': 'copyCallback',
        'click [data-action="rotate-callback"]': 'rotateCallback',
        'input [data-email-search]': 'renderEmails',
        'input [name="bodyHtml"], input [name="subject"], input [name="preheader"]': 'contentChanged',
        'change [data-preview-width]': 'renderContentPreview',
        'click [data-action="preview-contact"]': 'choosePreviewContact',
        'click [data-action="preview-design"]': 'clearRecipientPreview',
        'click [data-action="choose-template"]': 'chooseEmailTemplate',
        'click [data-action="apply-template"]': 'applyEmailTemplate',
        'click [data-action="cancel-template"]': 'cancelEmailTemplate',
        'change [data-email-status]': 'renderEmails',
        'change [name="campaignId"]': 'syncCampaign',
        'submit [data-email-form]': 'save',
        'submit [data-provider-form]': 'saveProvider',
        'submit [data-domain-form]': 'saveDomain',
    };

    setup() { this.setPageTitle('Marketing Email'); this.workspace = null; this.current = null; this._delivery = null; Object.defineProperty(this, 'delivery', {get: () => this._delivery, set: value => { this._delivery = value; queueMicrotask(() => this.renderCallback()); }}); }
    afterRender() { super.afterRender(); document.body.classList.add('nexa-marketing-email-page'); this.element.querySelector('[data-email-editor]').style.display='none'; this.element.querySelector('[data-delivery-editor]').style.display='none'; this.installCallbackSetup(); this.once('remove', () => document.body.classList.remove('nexa-marketing-email-page')); this.load(); }
    async load() { this.state('loading'); try { this.workspace = await Espo.Ajax.getRequest('Nexa/marketing-emails/workspace'); this.renderSummary(); this.populateCampaigns(); this.renderEmails(); this.text('[data-delivery-message]', this.workspace.deliveryMessage); this.element.querySelector('[data-action="new-email"]').hidden = !this.workspace.permissions?.create; this.element.querySelector('[data-action="delivery-setup"]').hidden = !this.workspace.permissions?.approve; this.state('ready'); } catch (error) { this.state('error'); Espo.Ui.error(error?.message || 'Marketing email could not be loaded.'); } }
    state(value) { this.element.querySelectorAll('[data-email-state]').forEach(node => { node.hidden = node.dataset.emailState !== value; node.style.display = node.hidden ? 'none' : ''; }); }
    renderSummary() {
        const summary = this.workspace.summary || {};
        ['total','drafts','review','approved'].forEach(key => this.text(`[data-summary-${key}]`, Number(summary[key] || 0)));
        this.text('[data-delivery-title]', this.workspace.deliveryEnabled ? 'Delivery is ready' : 'Delivery setup required');
        const section = this.element.querySelector('[data-email-usage]');
        if (section) {
            section.hidden = !this.workspace.usage;
            if (this.workspace.usage) {
                const usage = this.workspace.usage;
                this.text('[data-usage-month]', new Date(`${usage.month}T00:00:00`).toLocaleDateString(undefined, {month: 'long', year: 'numeric'}));
                this.text('[data-usage-limit]', usage.limit === null ? 'No configured cap' : Number(usage.limit).toLocaleString());
                this.text('[data-usage-reserved]', Number(usage.reserved).toLocaleString());
                this.text('[data-usage-remaining]', usage.remaining === null ? 'No configured cap' : Number(usage.remaining).toLocaleString());
            }
        }
    }
    openResults(event) {
        const item = this.workspace.emails.find(row => row.id === event.currentTarget.dataset.id);
        if (!item) return;
        this.resultsTrigger = event.currentTarget;
        const dialog = this.element.querySelector('[data-email-results]');
        const metrics = item.deliveryMetrics || {};
        this.text('[data-results-title]', item.name);
        this.text('[data-results-status]', this.label(item.lifecycleStatus));
        this.text('[data-results-campaign]', item.campaignName || 'Not linked');
        ['submitted','delivered','deferred','bounced','complained','failed','opened','clicked','unsubscribed'].forEach(key =>
            this.text(`[data-result-${key}]`, Number(metrics[key] || 0).toLocaleString()));
        dialog.hidden = false; dialog.style.display = 'grid';
        document.body.classList.add('nexa-modal-open');
        const close = dialog.querySelector('[data-action="close-results"]');
        close.focus();
        this.resultsKeyHandler = event => {
            if (event.key === 'Escape') { event.preventDefault(); this.closeResults(); }
            if (event.key === 'Tab') {
                const controls = [...dialog.querySelectorAll('button:not(:disabled),input:not(:disabled),select:not(:disabled)')];
                const index = controls.indexOf(document.activeElement);
                event.preventDefault(); controls[(index + (event.shiftKey ? controls.length - 1 : 1)) % controls.length]?.focus();
            }
        };
        dialog.addEventListener('keydown', this.resultsKeyHandler);
        this.installRecipientResults(dialog);
        this.recipientResultsId = item.id;
        this.loadDeliveryRecipients(true);
    }
    closeResults() {
        this.recipientRequest = (this.recipientRequest || 0) + 1;
        clearTimeout(this.recipientSearchTimer);
        this.recipientObserver?.disconnect();
        const dialog = this.element.querySelector('[data-email-results]');
        dialog.hidden = true; dialog.style.display = 'none';
        dialog.removeEventListener('keydown', this.resultsKeyHandler);
        document.body.classList.remove('nexa-modal-open');
        if (this.resultsTrigger?.isConnected) this.resultsTrigger.focus();
    }

    installRecipientResults(dialog) {
        if (!this.recipientRemovalHook) {
            this.recipientRemovalHook = true;
            this.once?.('remove', () => this.closeResults());
        }
        if (!dialog.querySelector('[data-recipient-results]')) {
            dialog.querySelector('section').insertAdjacentHTML('beforeend', '<div data-recipient-results class="nexa-recipient-results"><div class="nexa-recipient-toolbar"><input class="form-control" data-recipient-search type="search" placeholder="Search recipients" aria-label="Search recipients"><select class="form-control" data-recipient-status aria-label="Delivery status"><option value="">All delivery statuses</option><option value="submitting">Awaiting confirmation</option><option value="submitted">Submitted</option><option value="delivered">Delivered</option><option value="deferred">Delayed</option><option value="bounced">Bounced</option><option value="complained">Complaint</option><option value="failed">Failed</option></select></div><div class="nexa-recipient-scroll"><table class="table"><thead><tr><th>Recipient</th><th>Status</th><th>Submitted</th><th>Updated</th></tr></thead><tbody data-recipient-rows></tbody></table><div data-recipient-sentinel role="status"></div></div></div>');
        }
        dialog.querySelector('[data-recipient-search]').value = '';
        dialog.querySelector('[data-recipient-status]').value = '';
        this.recipientObserver?.disconnect();
        this.recipientObserver = new IntersectionObserver(entries => {
            if (entries.some(entry => entry.isIntersecting) && this.recipientCursor && !this.recipientLoading) this.loadDeliveryRecipients(false);
        }, {root: dialog.querySelector('.nexa-recipient-scroll'), rootMargin: '100px'});
        this.recipientObserver.observe(dialog.querySelector('[data-recipient-sentinel]'));
    }

    searchDeliveryRecipients() {
        this.recipientRequest = (this.recipientRequest || 0) + 1;
        this.recipientCursor = null;
        clearTimeout(this.recipientSearchTimer);
        this.recipientSearchTimer = setTimeout(() => this.loadDeliveryRecipients(true), 200);
    }

    async loadDeliveryRecipients(reset) {
        const dialog = this.element.querySelector('[data-email-results]');
        if (!dialog || dialog.hidden || !this.recipientResultsId) return;
        const request = this.recipientRequest = (this.recipientRequest || 0) + 1;
        const rows = dialog.querySelector('[data-recipient-rows]');
        const sentinel = dialog.querySelector('[data-recipient-sentinel]');
        if (reset) { rows.innerHTML = ''; this.recipientCursor = null; }
        const params = {search: dialog.querySelector('[data-recipient-search]').value.trim(), status: dialog.querySelector('[data-recipient-status]').value};
        if (!reset && this.recipientCursor) params.cursor = this.recipientCursor;
        this.recipientLoading = true; sentinel.textContent = 'Loading recipients...';
        try {
            const result = await Espo.Ajax.getRequest(`Nexa/marketing-emails/${encodeURIComponent(this.recipientResultsId)}/messages`, params);
            if (request !== this.recipientRequest || dialog.hidden) return;
            rows.insertAdjacentHTML('beforeend', (result.items || []).map(item => `<tr><td>${this.escape(item.recipient)}</td><td>${this.escape(this.label(item.status))}</td><td>${this.escape(this.dateTime(item.submittedAt))}</td><td>${this.escape(this.dateTime(item.updatedAt))}</td></tr>`).join(''));
            this.recipientCursor = result.nextCursor || null;
            sentinel.textContent = !rows.children.length && !this.recipientCursor ? 'No matching recipients.' : '';
            this.recipientLoading = false;
            if (this.recipientCursor && sentinel.getBoundingClientRect().top <= dialog.querySelector('.nexa-recipient-scroll').getBoundingClientRect().bottom + 100) this.loadDeliveryRecipients(false);
        } catch (error) {
            if (request !== this.recipientRequest || dialog.hidden) return;
            this.recipientLoading = false; this.recipientCursor = null;
            sentinel.textContent = error?.message || 'Recipient delivery results could not be loaded.';
        }
    }
    populateCampaigns() { const options = (this.workspace.campaigns || []).map(item => `<option value="${this.escape(item.id)}" data-purpose="${this.escape(item.purposeId || '')}">${this.escape(item.name)} (${Number(item.eligibleCount || 0)} eligible)</option>`).join(''); this.element.querySelector('[name="campaignId"]').innerHTML = `<option value="">Choose an email campaign</option>${options}`; }
    renderEmails() {
        const query = (this.element.querySelector('[data-email-search]')?.value || '').trim().toLowerCase(); const status = this.element.querySelector('[data-email-status]')?.value || '';
        const items = (this.workspace?.emails || []).filter(item => (!status || item.lifecycleStatus === status) && (!query || `${item.name} ${item.subject} ${item.campaignName || ''}`.toLowerCase().includes(query)));
        this.element.querySelector('[data-email-list]').innerHTML = items.map(item => `<tr><td><button class="nexa-email-name" data-action="edit-email" data-id="${this.escape(item.id)}"><span class="fas fa-envelope-open-text"></span><span><strong>${this.escape(item.name)}</strong><small>${this.escape(item.subject || 'No subject')}</small></span></button></td><td>${this.escape(item.campaignName || 'Not linked')}</td><td><span class="nexa-email-status is-${this.escape(item.lifecycleStatus)}">${this.escape(this.label(item.lifecycleStatus))}</span></td><td>v${Number(item.version || 1)}</td><td>${this.escape(item.fromAddress || 'Not set')}</td><td>${Number(item.queuedCount || 0)}</td><td>${this.escape(this.dateTime(item.modifiedAt))}</td><td><div class="nexa-email-actions">${this.actions(item)}</div></td></tr>`).join('');
        if (this.workspace.permissions?.approve) {
            this.element.querySelectorAll('[data-email-list] [data-action="control-delivery"][data-control="resume"]').forEach(button => {
                button.insertAdjacentHTML('beforebegin', `<button class="btn btn-icon" data-action="review-submissions" data-id="${this.escape(button.dataset.id)}" title="Review held submissions" aria-label="Review held submissions"><span class="fas fa-clipboard-check"></span></button>`);
            });
        }
        this.text('[data-email-count]', `${items.length} email${items.length === 1 ? '' : 's'}`); const empty = this.element.querySelector('[data-email-empty]'); empty.hidden = items.length > 0; this.element.querySelector('[data-email-table]').hidden = !items.length;
    }
    actions(item) {
        const id = this.escape(item.id);
        const buttons = [`<button class="btn btn-icon" data-action="email-results" data-id="${id}" title="Delivery results"><span class="fas fa-chart-bar"></span></button>`];
        if (['draft','review','approved'].includes(item.lifecycleStatus) && this.workspace.permissions?.edit) buttons.push(`<button class="btn btn-icon" data-action="edit-email" data-id="${id}" title="Edit"><span class="fas fa-pen"></span></button>`);
        if (item.lifecycleStatus === 'draft') buttons.push(this.transitionButton(item, 'submit', 'Submit for review', 'fa-paper-plane'));
        if (item.lifecycleStatus === 'review' && this.workspace.permissions?.approve) buttons.push(this.transitionButton(item, 'approve', 'Approve', 'fa-check'));
        if (item.lifecycleStatus === 'approved' && this.workspace.deliveryEnabled && this.workspace.permissions?.approve) {
            const single = item.deliveryMode === 'single_send';
            buttons.push(`<button class="btn btn-icon" data-action="${single ? 'single-send' : 'schedule-email'}" data-id="${id}" title="${single ? 'Send to one Contact' : 'Schedule approved email'}" aria-label="${single ? 'Send to one Contact' : 'Schedule approved email'}"><span class="fas ${single ? 'fa-paper-plane' : 'fa-clock'}" aria-hidden="true"></span></button>`);
        }
        if (['review','approved'].includes(item.lifecycleStatus)) buttons.push(this.transitionButton(item, 'return', 'Return to draft', 'fa-undo'));
        if (this.workspace.permissions?.approve && ['scheduled','sending','paused'].includes(item.lifecycleStatus)) {
            const control = item.lifecycleStatus === 'paused' ? 'resume' : 'pause';
            buttons.push(`<button class="btn btn-icon" data-action="control-delivery" data-id="${id}" data-control="${control}" title="${control === 'pause' ? 'Pause delivery' : 'Resume delivery'}"><span class="fas ${control === 'pause' ? 'fa-pause' : 'fa-play'}"></span></button>`);
        }
        buttons.push(`<button class="btn btn-icon" data-action="version-history" data-id="${id}" title="Content versions"><span class="fas fa-history"></span></button>`);
        if (item.lifecycleStatus === 'approved' && this.workspace.deliveryEnabled && this.workspace.permissions?.approve) buttons.push(`<button class="btn btn-icon" data-action="test-email" data-id="${id}" title="Send test to my email" aria-label="Send test to my email"><span class="fas fa-flask"></span></button>`);
        return buttons.join('');
    }
    transitionButton(item, action, title, icon) { return `<button class="btn btn-icon" data-action="transition" data-id="${this.escape(item.id)}" data-transition="${action}" title="${title}"><span class="fas ${icon}"></span></button>`; }

    async openSubmissionReview(event) {
        const id = event.currentTarget.dataset.id;
        const trigger = event.currentTarget;
        try {
            const result = await Espo.Ajax.getRequest(`Nexa/marketing-emails/${encodeURIComponent(id)}/submission-review`);
            if (!result.items?.length) { Espo.Ui.notify('No held submissions to review.', 'info'); return; }
            this.closeSubmissionReview();
            const modal = document.createElement('div');
            modal.className = 'nexa-marketing-email-editor';
            modal.dataset.submissionReview = id;
            modal.innerHTML = `<section class="nexa-marketing-email-dialog" role="dialog" aria-modal="true" aria-labelledby="nexa-submission-review-title"><header><h2 id="nexa-submission-review-title">Review held submission</h2><button type="button" class="btn btn-icon" data-action="close-submission-review" title="Close review" aria-label="Close review"><span class="fas fa-times"></span></button></header><form data-submission-review-form><section><label>Submission<select class="form-control" name="messageId" required>${result.items.map(item => `<option value="${this.escape(item.id)}">${this.escape(item.recipient)} - ${this.escape(this.dateTime(item.createdAt))}</option>`).join('')}</select></label><label>Provider evidence outcome<select class="form-control" name="decision" required><option value="">Choose an outcome</option><option value="accepted">Postal accepted it</option><option value="not_accepted">Postal did not accept it</option></select></label><label>Confirmed Postal message ID<input class="form-control" name="providerMessageId" maxlength="255" disabled></label><label>Provider evidence note<textarea class="form-control" name="evidenceNote" rows="4" minlength="10" maxlength="2000" required></textarea></label><p data-review-error role="alert"></p></section><footer><button type="button" class="btn btn-default" data-action="close-submission-review">Cancel</button><button type="submit" class="btn btn-primary"><span class="fas fa-check"></span>Record resolution</button></footer></form></section>`;
            this.element.appendChild(modal);
            this.reviewTrigger = trigger;
            document.body.classList.add('nexa-modal-open');
            modal.addEventListener('keydown', event => {
                if (event.key === 'Escape') { event.preventDefault(); this.closeSubmissionReview(); }
                if (event.key === 'Tab') {
                    const controls = [...modal.querySelectorAll('button:not(:disabled),input:not(:disabled),select:not(:disabled),textarea:not(:disabled)')];
                    const index = controls.indexOf(document.activeElement);
                    event.preventDefault(); controls[(index + (event.shiftKey ? controls.length - 1 : 1)) % controls.length]?.focus();
                }
            });
            modal.querySelector('select').focus();
            this.once('remove', () => this.closeSubmissionReview());
        } catch (error) { Espo.Ui.error(error?.message || 'Held submissions could not be loaded.'); }
    }

    updateReviewDecision() {
        const form = this.element.querySelector('[data-submission-review-form]');
        if (!form) return;
        const accepted = form.elements.decision.value === 'accepted';
        form.elements.providerMessageId.disabled = !accepted;
        form.elements.providerMessageId.required = accepted;
    }

    closeSubmissionReview() {
        const modal = this.element.querySelector('[data-submission-review]');
        if (!modal) return;
        modal.remove();
        document.body.classList.remove('nexa-modal-open');
        if (this.reviewTrigger?.isConnected) this.reviewTrigger.focus();
    }

    async resolveSubmission(event) {
        event.preventDefault();
        const form = event.currentTarget;
        if (!form.reportValidity()) return;
        const modal = form.closest('[data-submission-review]');
        const button = form.querySelector('[type="submit"]');
        if (button.disabled) return;
        button.disabled = true;
        try {
            await Espo.Ajax.postRequest(`Nexa/marketing-emails/${encodeURIComponent(modal.dataset.submissionReview)}/submission-review`, Object.fromEntries(new FormData(form)));
            this.closeSubmissionReview();
            Espo.Ui.success('Resolution recorded. Delivery remains paused.');
            await this.load();
        } catch (error) {
            form.querySelector('[data-review-error]').textContent = error?.message || 'The submission could not be resolved.';
            button.disabled = false;
        }
    }
    newEmail() { this.pendingTemplate = null; this.templateRequest = (this.templateRequest || 0) + 1; this.previewRequest = (this.previewRequest || 0) + 1; this.recipientPreview = null; this.openEditor(null); }
    contentChanged() { this.pendingTemplate = null; this.templateRequest = (this.templateRequest || 0) + 1; this.renderContentPreview(); }
    chooseEmailTemplate() {
        const editor = this.element.querySelector('[data-email-editor]'); editor.style.display = 'none';
        this.createView('marketingTemplateSelector', 'views/modals/select-records', {
            scope: 'EmailTemplate', multiple: false, createButton: false, headerText: 'Choose an email template',
        }, view => {
            this.listenToOnce(view, 'remove', () => { editor.style.display = 'grid'; });
            this.listenToOnce(view, 'select', model => this.loadEmailTemplate(model.id)); view.render();
        });
    }
    async loadEmailTemplate(id) {
        const request = this.templateRequest = (this.templateRequest || 0) + 1;
        try {
            const template = await Espo.Ajax.getRequest(`Nexa/marketing-email-templates/${encodeURIComponent(id)}`);
            if (request !== this.templateRequest || this.element.querySelector('[data-email-editor]').hidden) return;
            if (typeof template.body !== 'string' || !template.body.trim()) throw new Error('The template has no accessible content.');
            this.pendingTemplate = {id, name: template.name || 'Email template', subject: template.subject || '',
                bodyHtml: template.isHtml === false ? `<p>${this.escape(template.body).replace(/\r?\n/g, '<br>')}</p>` : template.body,
                attachments: template.attachments || []};
            this.renderContentPreview();
        } catch (error) { Espo.Ui.error(error?.message || 'The template could not be loaded.'); }
    }
    applyEmailTemplate() {
        if (!this.pendingTemplate) return;
        const form = this.element.querySelector('[data-email-form]');
        form.elements.subject.value = this.pendingTemplate.subject; form.elements.bodyHtml.value = this.pendingTemplate.bodyHtml;
        this.selectedAttachments = [...(this.pendingTemplate.attachments || [])];
        this.renderAttachments();
        this.contentRichDirty = false;
        this.contentModel?.set('body', this.pendingTemplate.bodyHtml);
        this.pendingTemplate = null; this.recipientPreview = null; this.previewRequest = (this.previewRequest || 0) + 1;
        this.renderContentPreview();
        (this.contentSourceMode || !this.contentEditor ? form.elements.bodyHtml : form.querySelector('.note-editable'))?.focus();
    }
    cancelEmailTemplate() { this.pendingTemplate = null; this.templateRequest = (this.templateRequest || 0) + 1; this.renderContentPreview(); }
    async openConditionalContent(event) {
        if (this.conditionalDialog) return;
        const generation = this.contentEditorRequest;
        const request = this.conditionalRequest = (this.conditionalRequest || 0) + 1;
        const trigger = event?.currentTarget;
        try {
            const result = await Espo.Ajax.getRequest('Nexa/marketing-email-conditions/fields');
            if (request !== this.conditionalRequest || generation !== this.contentEditorRequest || this.element.querySelector('[data-email-editor]').hidden) return;
            const modal = document.createElement('div'); modal.className = 'nexa-marketing-email-editor nexa-conditional-overlay';
            modal.dataset.conditionalDialog = '';
            modal.innerHTML = `<section class="nexa-marketing-email-dialog nexa-conditional-dialog" role="dialog" aria-modal="true" aria-labelledby="nexa-conditional-title"><header><h2 id="nexa-conditional-title">Conditional content</h2><button type="button" class="btn btn-icon" data-action="close-conditional-content" title="Close conditional content" aria-label="Close conditional content"><span class="fas fa-times" aria-hidden="true"></span></button></header><form data-conditional-form><section><label>Contact property<select class="form-control" name="field" required>${result.fields.map(field => `<option value="${this.escape(field.name)}">${this.escape(field.label)}</option>`).join('')}</select></label><label>Condition<select class="form-control" name="operator"><option value="equals">Is equal to</option><option value="not_equals">Is not equal to</option><option value="has_value">Has a value</option><option value="no_value">Has no value</option></select></label><label data-conditional-value>Value<input class="form-control" name="value" maxlength="255" required></label><label>Content when matched<textarea class="form-control" name="matchingText" rows="5" maxlength="10000" required></textarea></label><label>Fallback content<textarea class="form-control" name="fallbackText" rows="5" maxlength="10000" required></textarea></label><p data-conditional-error role="alert"></p></section><footer><button type="button" class="btn btn-default" data-action="close-conditional-content">Cancel</button><button type="submit" class="btn btn-primary"><span class="fas fa-plus" aria-hidden="true"></span>Insert content</button></footer></form></section>`;
            this.conditionalDialog = {modal,generation,fields:result.fields,trigger};
            this.element.append(modal); this.updateConditionalValue();
            modal.addEventListener('keydown', key => {
                if (key.key === 'Escape') {key.preventDefault(); this.closeConditionalContent();}
                if (key.key === 'Tab') {
                    const controls = [...modal.querySelectorAll('button:not(:disabled),input:not(:disabled),select:not(:disabled),textarea:not(:disabled)')];
                    const index = controls.indexOf(document.activeElement); key.preventDefault();
                    controls[(index + (key.shiftKey ? controls.length - 1 : 1)) % controls.length]?.focus();
                }
            });
            modal.querySelector('select').focus();
            if (!this.conditionalRemovalHook) {this.conditionalRemovalHook = true; this.once?.('remove', () => this.closeConditionalContent());}
        } catch (error) {
            if (request === this.conditionalRequest && generation === this.contentEditorRequest) Espo.Ui.error(error?.message || 'Conditional content could not be opened.');
        }
    }
    updateConditionalValue() {
        const dialog = this.conditionalDialog;
        if (!dialog) return;
        const form = dialog.modal.querySelector('form');
        const options = dialog.fields.find(field => field.name === form.elements.field.value)?.options || [];
        const previous = dialog.valueField === form.elements.field.value ? form.elements.value.value : null;
        dialog.valueField = form.elements.field.value;
        const control = options.length ? document.createElement('select') : document.createElement('input');
        control.name = 'value'; control.className = 'form-control'; control.required = true;
        if (options.length) options.forEach(option => control.add(new Option(option.label,option.value)));
        else control.maxLength = 255;
        if (previous !== null && (!options.length || options.some(option => option.value === previous))) control.value = previous;
        form.elements.value.replaceWith(control);
        const needsValue = ['equals','not_equals'].includes(form.elements.operator.value);
        form.querySelector('[data-conditional-value]').hidden = !needsValue;
        control.disabled = !needsValue; control.required = needsValue;
    }
    closeConditionalContent() {
        this.conditionalRequest = (this.conditionalRequest || 0) + 1;
        const dialog = this.conditionalDialog; this.conditionalDialog = null;
        dialog?.modal.remove(); if (dialog?.trigger?.isConnected) dialog.trigger.focus();
    }
    async insertConditionalContent(event) {
        event.preventDefault();
        const dialog = this.conditionalDialog;
        if (!dialog || dialog.generation !== this.contentEditorRequest) return this.closeConditionalContent();
        const form = dialog.modal.querySelector('form');
        if (!form.reportValidity()) return;
        const button = form.querySelector('[type="submit"]'); if (button.disabled) return;
        this.syncRichContent();
        const source = this.element.querySelector('[name="bodyHtml"]'); const previous = source.value;
        button.disabled = true; form.querySelector('[data-conditional-error]').textContent = '';
        try {
            const result = await Espo.Ajax.postRequest('Nexa/marketing-email-conditions',Object.fromEntries(new FormData(form)));
            if (this.conditionalDialog !== dialog || dialog.generation !== this.contentEditorRequest || this.element.querySelector('[data-email-editor]').hidden) return;
            this.syncRichContent();
            if (source.value !== previous) throw new Error('Email content changed. Review your condition and insert it again.');
            if (typeof result.bodyHtml !== 'string' || previous.length + result.bodyHtml.length > 2_000_000) throw new Error('Conditional content is too large.');
            source.value = previous + result.bodyHtml; this.contentModel?.set('body',source.value); this.contentRichDirty = false;
            this.contentChanged(); this.closeConditionalContent();
        } catch (error) {
            if (this.conditionalDialog === dialog) form.querySelector('[data-conditional-error]').textContent = error?.message || 'Conditional content could not be inserted.';
        } finally {button.disabled = false;}
    }
    openBlockDesigner() {
        if (this.attachmentUploading || this.blockDesigner) return;
        this.syncRichContent();
        const form = this.element.querySelector('[data-email-form]');
        const overlay = document.createElement('section');
        overlay.className = 'nexa-block-designer'; overlay.dataset.blockDesigner = '';
        overlay.setAttribute('role', 'dialog'); overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'nexa-block-designer-title');
        overlay.innerHTML = '<header><h2 id="nexa-block-designer-title">Email designer</h2><span data-designer-status role="status">Loading designer</span><button type="button" class="btn btn-primary" data-action="apply-block-design" disabled><span class="fas fa-check" aria-hidden="true"></span>Apply design</button><button type="button" class="btn btn-icon" data-action="close-block-designer" title="Cancel design" aria-label="Cancel design"><span class="fas fa-times" aria-hidden="true"></span></button></header><iframe title="Email block designer" sandbox="allow-scripts allow-same-origin"></iframe>';
        this.element.append(overlay);
        const frame = overlay.querySelector('iframe');
        const session = Array.from(crypto.getRandomValues(new Uint8Array(16)), byte => byte.toString(16).padStart(2, '0')).join('');
        const origin = location.origin;
        const draftGeneration = this.contentEditorRequest;
        this.blockDesigner = {overlay, frame, session, draftGeneration};
        this.blockDesigner.timer = setTimeout(() => {
            if (this.blockDesigner?.session === session) overlay.querySelector('[data-designer-status]').textContent = 'Designer unavailable. Close and try again.';
        }, 15000);
        overlay.addEventListener('keydown', event => {if (event.key === 'Escape') {event.preventDefault(); this.closeBlockDesigner();}});
        this.blockDesignerListener = event => {
            if (!this.blockDesigner || event.source !== frame.contentWindow || event.origin !== origin || event.data?.session !== session) return;
            if (draftGeneration !== this.contentEditorRequest || this.element.querySelector('[data-email-editor]').hidden) return this.closeBlockDesigner();
            if (event.data.type === 'nexa-email-designer-ready') {
                clearTimeout(this.blockDesigner.timer);
                overlay.querySelector('[data-designer-status]').textContent = '';
                overlay.querySelector('[data-action="apply-block-design"]').disabled = false;
            }
            if (event.data.type === 'nexa-email-designer-error') {
                const revoked = event.data.reason === 'revoked-image';
                overlay.querySelector('[data-designer-status]').textContent = revoked ? 'Remove the revoked image' : 'Designer unavailable';
                Espo.Ui.error(revoked ? 'Remove the revoked email image before applying this design. Your saved draft is unchanged.' : 'The design could not be loaded or exported. Your draft is unchanged.');
            }
            if (event.data.type === 'nexa-email-designer-cancel') this.closeBlockDesigner();
            if (event.data.type === 'nexa-email-designer-image-upload') {
                const input = event.data;
                if (this.blockDesigner.imageUploading) return;
                if (input.confirmPublicCopy !== true || typeof input.id !== 'string' || input.id.length > 64 ||
                    typeof input.name !== 'string' || !input.name.trim() || input.name.length > 255 ||
                    !['image/png', 'image/jpeg', 'image/gif', 'image/webp'].includes(input.mimeType) ||
                    typeof input.data !== 'string' || input.data.length > 4 * Math.ceil(8 * 1024 * 1024 / 3) || !/^[A-Za-z0-9+/]+={0,2}$/.test(input.data)) {
                    frame.contentWindow.postMessage({type: 'nexa-email-designer-image-error', session}, origin); return;
                }
                this.blockDesigner.imageUploading = true;
                overlay.querySelector('[data-action="apply-block-design"]').disabled = true;
                let assetId;
                Espo.Ajax.postRequest('Nexa/assets', {displayName: input.name, fileName: input.name, mimeType: input.mimeType, data: input.data, accessScope: 'public'}).then(asset => {
                    assetId = asset.id;
                    if (this.blockDesigner?.session !== session || draftGeneration !== this.contentEditorRequest) return;
                    return Espo.Ajax.postRequest('Nexa/marketing-email-images', {assetId, confirmPublicCopy: true});
                }).then(image => {
                    if (!image || this.blockDesigner?.session !== session || draftGeneration !== this.contentEditorRequest) return;
                    frame.contentWindow.postMessage({type: 'nexa-email-designer-image-result', session, image, requestedId: input.id}, origin);
                }).catch(() => {
                    if (this.blockDesigner?.session === session && draftGeneration === this.contentEditorRequest) {
                        frame.contentWindow.postMessage({type: 'nexa-email-designer-image-error', session, reason: assetId ? 'uploaded-not-published' : 'upload-failed'}, origin);
                        if (assetId) Espo.Ajax.getRequest('Nexa/marketing-email-images').then(result => {
                            if (this.blockDesigner?.session === session && draftGeneration === this.contentEditorRequest) {
                                frame.contentWindow.postMessage({type: 'nexa-email-designer-images', session, images: result.images}, origin);
                                frame.contentWindow.postMessage({type: 'nexa-email-designer-image-error', session, reason: 'uploaded-not-published'}, origin);
                            }
                        }).catch(() => {});
                    }
                }).finally(() => {
                    if (this.blockDesigner?.session === session) {
                        this.blockDesigner.imageUploading = false;
                        overlay.querySelector('[data-action="apply-block-design"]').disabled = false;
                    }
                });
            }
            if (event.data.type === 'nexa-email-designer-image-publish' && typeof event.data.assetId === 'string' && typeof event.data.id === 'string') {
                Espo.Ajax.postRequest('Nexa/marketing-email-images', {assetId: event.data.assetId, confirmPublicCopy: true}).then(image => {
                    if (this.blockDesigner?.session !== session || draftGeneration !== this.contentEditorRequest) return;
                    frame.contentWindow.postMessage({type: 'nexa-email-designer-image-result', session, image, requestedId: event.data.id}, origin);
                }).catch(() => {
                    if (this.blockDesigner?.session === session) frame.contentWindow.postMessage({type: 'nexa-email-designer-image-error', session}, origin);
                });
            }
            if (event.data.type === 'nexa-email-designer-image-revoke' && typeof event.data.id === 'string') {
                Espo.Ajax.postRequest(`Nexa/marketing-email-images/${encodeURIComponent(event.data.id)}/revoke`, {}).then(() => {
                    if (this.blockDesigner?.session === session && draftGeneration === this.contentEditorRequest) {
                        frame.contentWindow.postMessage({type: 'nexa-email-designer-image-revoked', session, id: event.data.id}, origin);
                    }
                }).catch(() => {
                    if (this.blockDesigner?.session === session) frame.contentWindow.postMessage({type: 'nexa-email-designer-image-error', session}, origin);
                });
            }
            if (event.data.type === 'nexa-email-designer-image' && typeof event.data.id === 'string') {
                Espo.Ajax.getRequest(`Nexa/marketing-email-images/${encodeURIComponent(event.data.id)}`).then(image => {
                    if (this.blockDesigner?.session !== session || draftGeneration !== this.contentEditorRequest) return;
                    frame.contentWindow.postMessage({type: 'nexa-email-designer-image-result', session, image}, origin);
                }).catch(() => {
                    if (this.blockDesigner?.session === session) frame.contentWindow.postMessage({type: 'nexa-email-designer-image-error', session}, origin);
                });
            }
            if (event.data.type === 'nexa-email-designer-content' && typeof event.data.html === 'string' && event.data.html.length <= 2_000_000) {
                if (draftGeneration !== this.contentEditorRequest || this.element.querySelector('[data-email-editor]').hidden) return this.closeBlockDesigner();
                form.elements.bodyHtml.value = event.data.html;
                this.contentModel?.set('body', event.data.html); this.contentRichDirty = false;
                this.contentChanged(); this.closeBlockDesigner();
            }
        };
        window.addEventListener('message', this.blockDesignerListener);
        frame.addEventListener('load', () => {
            if (this.blockDesigner?.session === session) frame.contentWindow.postMessage({type: 'nexa-email-designer-init', session, html: form.elements.bodyHtml.value}, origin);
            if (Espo.Ajax) Espo.Ajax.getRequest('Nexa/marketing-email-images').then(result => {
                if (this.blockDesigner?.session === session && draftGeneration === this.contentEditorRequest) {
                    frame.contentWindow.postMessage({type: 'nexa-email-designer-images', session, images: result.images}, origin);
                }
            }).catch(() => {
                if (this.blockDesigner?.session === session) frame.contentWindow.postMessage({type: 'nexa-email-designer-image-error', session}, origin);
            });
        });
        frame.src = `${this.getBasePath()}client/custom/email-designer/index.html`;
        overlay.querySelector('[data-action="close-block-designer"]').focus();
        if (!this.blockDesignerRemovalHook) {this.blockDesignerRemovalHook = true; this.once('remove', () => this.closeBlockDesigner());}
    }
    applyBlockDesign() {
        const designer = this.blockDesigner;
        if (!designer) return;
        if (designer.draftGeneration !== this.contentEditorRequest || this.element.querySelector('[data-email-editor]').hidden) return this.closeBlockDesigner();
        if (designer.imageUploading) return;
        designer.frame.contentWindow.postMessage({type: 'nexa-email-designer-export', session: designer.session}, location.origin);
    }
    closeBlockDesigner() {
        if (!this.blockDesigner) return;
        clearTimeout(this.blockDesigner.timer);
        window.removeEventListener('message', this.blockDesignerListener);
        this.blockDesigner.overlay.remove(); this.blockDesigner = null; this.blockDesignerListener = null;
        this.element.querySelector('[data-action="open-block-designer"]')?.focus();
    }
    async openVersionHistory(event) {
        const id = event.currentTarget.dataset.id;
        this.historyTrigger = event.currentTarget;
        let modal = this.element.querySelector('[data-email-history]');
        if (!modal) {
            modal = document.createElement('div'); modal.className = 'nexa-marketing-email-editor'; modal.dataset.emailHistory = '';
            modal.innerHTML = '<section class="nexa-marketing-email-dialog nexa-email-history-dialog" role="dialog" aria-modal="true" aria-labelledby="nexa-history-title"><header><h2 id="nexa-history-title">Content versions</h2><button class="btn btn-icon" data-action="close-history" type="button" title="Close content versions" aria-label="Close content versions"><span class="fas fa-times"></span></button></header><div class="nexa-history-content"><p data-history-state role="status">Loading versions...</p><label>Version<select class="form-control" data-history-version aria-label="Content version"></select></label><strong data-history-subject></strong><iframe title="Content version preview" sandbox="" referrerpolicy="no-referrer"></iframe></div><footer><button class="btn btn-default" data-action="close-history" type="button">Close</button><button class="btn btn-primary" data-action="restore-version" type="button" disabled><span class="fas fa-undo"></span>Restore as draft</button></footer></section>';
            this.element.append(modal);
            modal.addEventListener('keydown', event => {
                if (event.key === 'Escape') { event.preventDefault(); this.closeVersionHistory(); }
                if (event.key === 'Tab') {
                    const controls = [...modal.querySelectorAll('button:not(:disabled),select:not(:disabled)')].filter(node => !node.hidden);
                    const index = controls.indexOf(document.activeElement);
                    event.preventDefault(); controls[(index + (event.shiftKey ? controls.length - 1 : 1)) % controls.length]?.focus();
                }
            });
        }
        this.history = {id}; modal.hidden = false; modal.style.display = 'grid'; document.body.classList.add('nexa-modal-open');
        modal.querySelector('[data-action="close-history"]').focus();
        this.text('[data-history-state]', 'Loading versions...');
        modal.querySelector('select').innerHTML = ''; modal.querySelector('iframe').srcdoc = '';
        modal.querySelector('[data-action="restore-version"]').disabled = true;
        try {
            const history = await Espo.Ajax.getRequest(`Nexa/marketing-emails/${encodeURIComponent(id)}/versions`);
            if (this.history?.id !== id || modal.hidden) return;
            this.history = {...history, id};
            modal.querySelector('select').innerHTML = history.versions.map(version => `<option value="${Number(version.version)}">v${Number(version.version)} - ${this.escape(this.dateTime(version.createdAt))} - ${this.escape(version.createdByName || 'Unknown author')}</option>`).join('');
            await this.loadHistoryVersion();
        } catch (error) { this.text('[data-history-state]', error?.message || 'Content versions could not be loaded.'); }
    }
    async loadHistoryVersion() {
        const modal = this.element.querySelector('[data-email-history]');
        const version = Number(modal.querySelector('select').value), id = this.history?.id;
        if (!id || !version) { this.text('[data-history-state]', 'No content versions found.'); return; }
        const request = this.historyRequest = (this.historyRequest || 0) + 1;
        this.text('[data-history-state]', 'Loading content...');
        modal.querySelector('[data-action="restore-version"]').disabled = true;
        try {
            const content = await Espo.Ajax.getRequest(`Nexa/marketing-emails/${encodeURIComponent(id)}/versions/${version}`);
            if (request !== this.historyRequest || this.history?.id !== id || modal.hidden) return;
            this.text('[data-history-subject]', content.subject);
            const policy = "default-src 'none'; img-src data:; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'";
            modal.querySelector('iframe').srcdoc = `<meta http-equiv="Content-Security-Policy" content="${policy}"><style>body{font:14px Arial;color:#17211f;margin:20px;overflow-wrap:anywhere}img{max-width:100%}</style>${content.bodyHtml}`;
            this.text('[data-history-state]', version === Number(this.history.currentVersion) ? 'Current version' : `Version ${version}`);
            modal.querySelector('[data-action="restore-version"]').disabled = !this.history.canRestore || version === Number(this.history.currentVersion);
        } catch (error) { this.text('[data-history-state]', error?.message || 'Content could not be loaded.'); }
    }
    closeVersionHistory() {
        const modal = this.element.querySelector('[data-email-history]'); modal.hidden = true; modal.style.display = 'none';
        this.history = null; this.historyRequest = (this.historyRequest || 0) + 1; document.body.classList.remove('nexa-modal-open');
        if (this.historyTrigger?.isConnected) this.historyTrigger.focus();
    }
    restoreHistoryVersion() {
        const id = this.history?.id, version = Number(this.element.querySelector('[data-history-version]').value);
        if (!id || !this.history.canRestore || version === Number(this.history.currentVersion)) return;
        this.confirm({message: `Restore version ${version} as a new draft? Approval will be cleared.`, confirmText: 'Restore draft'}, async () => {
            try {
                await Espo.Ajax.postRequest(`Nexa/marketing-emails/${encodeURIComponent(id)}/versions/${version}/restore`, {});
                this.closeVersionHistory(); await this.load(); Espo.Ui.success('Content restored as a new draft.');
            } catch (error) { Espo.Ui.error(error?.message || 'Content could not be restored.'); }
        });
    }
    controlDelivery(event) {
        const {id, control} = event.currentTarget.dataset;
        const message = control === 'pause' ? 'Pause delivery? Messages already submitted may still be delivered.' : 'Resume this email? Eligibility and monthly limits remain enforced. Unconfirmed submissions must be resolved first.';
        this.confirm({message, confirmText: control === 'pause' ? 'Pause delivery' : 'Resume delivery'}, async () => {
            try {
                await Espo.Ajax.postRequest(`Nexa/marketing-emails/${encodeURIComponent(id)}/control`, {action: control});
                Espo.Ui.success(control === 'pause' ? 'Delivery paused.' : 'Delivery resumed.');
                await this.load();
            } catch (error) { Espo.Ui.error(error?.message || 'Delivery could not be updated.'); }
        });
    }
    renderContentPreview() {
        const form = this.element.querySelector('[data-email-form]');
        if (!form.querySelector('[data-template-toolbar]')) {
            const tools = document.createElement('div'); tools.dataset.templateToolbar = ''; tools.className = 'nexa-template-toolbar';
            tools.innerHTML = '<button class="btn btn-default" type="button" data-action="choose-template"><span class="fas fa-file-alt" aria-hidden="true"></span>Use template</button><span data-template-name aria-live="polite"></span><button class="btn btn-primary" type="button" data-action="apply-template" hidden><span class="fas fa-check" aria-hidden="true"></span>Apply template</button><button class="btn btn-icon" type="button" data-action="cancel-template" title="Cancel template selection" aria-label="Cancel template selection" hidden><span class="fas fa-times" aria-hidden="true"></span></button>';
            form.querySelector('.nexa-email-code').before(tools);
        }
        form.querySelector('[data-template-name]').textContent = this.pendingTemplate ? `${this.pendingTemplate.name}${this.pendingTemplate.attachments?.length ? ' - ' + this.pendingTemplate.attachments.map(file => file.name).join(', ') : ''}` : '';
        if (!form.querySelector('[data-action="open-block-designer"]')) {
            const button = document.createElement('button'); button.type = 'button'; button.className = 'btn btn-default'; button.dataset.action = 'open-block-designer';
            button.innerHTML = '<span class="fas fa-th-large" aria-hidden="true"></span>Design blocks';
            form.querySelector('[data-template-toolbar]').append(button);
        }
        if (!form.querySelector('[data-action="conditional-content"]')) {
            const button = document.createElement('button'); button.type = 'button'; button.className = 'btn btn-default'; button.dataset.action = 'conditional-content';
            button.innerHTML = '<span class="fas fa-code-branch" aria-hidden="true"></span>Conditional content';
            form.querySelector('[data-template-toolbar]').append(button);
        }
        ['apply-template','cancel-template'].forEach(action => { form.querySelector(`[data-action="${action}"]`).hidden = !this.pendingTemplate; });
        let preview = form.querySelector('[data-content-preview]');
        if (!preview) {
            preview = document.createElement('section');
            preview.dataset.contentPreview = '';
            preview.className = 'nexa-email-content-preview';
            preview.innerHTML = '<header><h3>Preview</h3><div class="nexa-preview-controls"><button class="btn btn-default" type="button" data-action="preview-contact"><span class="fas fa-user" aria-hidden="true"></span>Preview as Contact</button><button class="btn btn-icon" type="button" data-action="preview-design" title="Return to design preview" aria-label="Return to design preview" hidden><span class="fas fa-times" aria-hidden="true"></span></button><select class="form-control" data-preview-width aria-label="Preview width"><option value="desktop">Desktop</option><option value="mobile">Mobile</option></select></div></header><p data-preview-recipient aria-live="polite"></p><strong data-preview-subject></strong><p data-preview-preheader></p><div class="nexa-email-preview-viewport"><iframe title="Email content preview" sandbox="" referrerpolicy="no-referrer"></iframe></div>';
            form.querySelector('.nexa-marketing-email-grid').append(preview);
        }
        const key = JSON.stringify([form.elements.subject.value, form.elements.bodyHtml.value]);
        const rendered = !this.pendingTemplate && this.recipientPreview?.key === key ? this.recipientPreview : null;
        preview.querySelector('[data-preview-subject]').textContent = this.pendingTemplate?.subject || rendered?.subject || form.elements.subject.value || 'No subject';
        preview.querySelector('[data-action="preview-contact"]').disabled = !!this.pendingTemplate;
        preview.querySelector('[data-preview-recipient]').textContent = rendered ? `Preview for ${rendered.name}` : '';
        preview.querySelector('[data-action="preview-design"]').hidden = !rendered;
        preview.querySelector('[data-preview-preheader]').textContent = form.elements.preheader.value;
        const frame = preview.querySelector('iframe');
        frame.classList.toggle('is-mobile', preview.querySelector('select').value === 'mobile');
        // The preview is deliberately inert, including remote images and stylesheets.
        const policy = "default-src 'none'; img-src data:; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'";
        frame.srcdoc = `<html><head><meta http-equiv="Content-Security-Policy" content="${policy}"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:20px;color:#17211f;font:14px Arial,sans-serif;overflow-wrap:anywhere}img{max-width:100%;height:auto}</style></head><body>${this.pendingTemplate?.bodyHtml ?? rendered?.bodyHtml ?? form.elements.bodyHtml.value}</body></html>`;
    }
    clearRecipientPreview() { this.previewRequest = (this.previewRequest || 0) + 1; this.recipientPreview = null; this.renderContentPreview(); }
    renderAttachments() {
        const form = this.element.querySelector('[data-email-form]');
        let panel = form.querySelector('[data-marketing-attachments]');
        if (!panel) {
            panel = document.createElement('div'); panel.dataset.marketingAttachments = '';
            panel.className = 'nexa-marketing-attachments';
            form.querySelector('.nexa-email-code').after(panel);
        }
        panel.hidden = !this.workspace?.permissions?.attachments;
        panel.innerHTML = `<header><strong>Attachments</strong><button type="button" class="btn btn-default" data-action="choose-attachment" ${this.selectedAttachments?.length >= 10 ? 'disabled' : ''}><span class="fas fa-paperclip" aria-hidden="true"></span>Add file</button></header><small>PDF, TXT, CSV, JPG, PNG, GIF or WebP. Maximum 5 MB each, 10 MB total.</small><ul>${(this.selectedAttachments || []).map(file => `<li><span>${this.escape(file.name)}</span><button type="button" class="btn btn-icon" data-action="remove-attachment" data-id="${this.escape(file.id)}" title="Remove attachment" aria-label="Remove ${this.escape(file.name)}"><span class="fas fa-times" aria-hidden="true"></span></button></li>`).join('')}</ul>`;
    }
    chooseAttachment() {
        if (!this.workspace?.permissions?.attachments || this.attachmentUploading) return;
        const editor = this.element.querySelector('[data-email-editor]');
        editor.style.display = 'none';
        this.createView('marketingAttachmentSelector', 'custom:views/wysiwyg/modals/tenant-file', {
            accept: '.pdf,.txt,.csv,.png,.jpg,.jpeg,.gif,.webp', maxFileSize: 5 * 1024 * 1024,
            helpText: 'PDF, TXT, CSV, JPG, PNG, GIF or WebP. Maximum 5 MB each.',
        }, view => {
            this.listenToOnce(view, 'remove', () => {if (!editor.hidden) editor.style.display = 'grid';});
            this.listenToOnce(view, 'upload', file => this.uploadMarketingAttachment(file));
            this.listenToOnce(view, 'insert', file => {
                if (editor.hidden || this.selectedAttachments.some(item => item.id === file.id)) return;
                if (!/\.(pdf|txt|csv|png|jpe?g|gif|webp)$/i.test(file.name)) return Espo.Ui.error('Choose a supported marketing attachment.');
                if (file.size > 5 * 1024 * 1024) return Espo.Ui.error('Each marketing attachment must be no larger than 5 MB.');
                if ((this.selectedAttachments.reduce((total, item) => total + Number(item.size || 0), 0) + Number(file.size || 0)) > 10 * 1024 * 1024) return Espo.Ui.error('Marketing attachments cannot exceed 10 MB in total.');
                if (this.selectedAttachments.length >= 10) return Espo.Ui.error('An email can contain at most 10 attachments.');
                this.selectedAttachments.push(file); this.renderAttachments();
            });
            view.render();
        });
    }
    removeAttachment(event) {
        this.selectedAttachments = (this.selectedAttachments || []).filter(file => file.id !== event.currentTarget.dataset.id);
        this.renderAttachments();
    }
    async uploadMarketingAttachment(file) {
        if (!this.workspace?.permissions?.attachments || this.attachmentUploading) return;
        const editor = this.element.querySelector('[data-email-editor]');
        if (editor.hidden) return;
        if (!/\.(pdf|txt|csv|png|jpe?g|gif|webp)$/i.test(file.name) || file.size <= 0 || file.size > 5 * 1024 * 1024) return Espo.Ui.error('Choose a supported marketing attachment no larger than 5 MB.');
        if ((this.selectedAttachments || []).length >= 10 || (this.selectedAttachments || []).reduce((total, item) => total + Number(item.size || 0), 0) + file.size > 10 * 1024 * 1024) return Espo.Ui.error('An email can contain at most 10 attachments and 10 MB in total.');
        const generation = this.contentEditorRequest;
        this.attachmentUploading = true;
        const button = this.element.querySelector('[data-action="choose-attachment"]');
        const saveButton = this.element.querySelector('[data-email-form] [type="submit"]');
        button.disabled = true; button.setAttribute('aria-busy', 'true');
        saveButton.disabled = true;
        try {
            const data = await new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.onload = () => resolve(String(reader.result).split(',')[1]);
                reader.onerror = () => reject(new Error('The attachment could not be read.'));
                reader.readAsDataURL(file);
            });
            const mimeType = {txt: 'text/plain', csv: 'text/csv', pdf: 'application/pdf', png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg', gif: 'image/gif', webp: 'image/webp'}[file.name.split('.').pop().toLowerCase()];
            const uploaded = await Espo.Ajax.postRequest('Nexa/marketing-email-attachments', {name: file.name, mimeType, data});
            if (generation !== this.contentEditorRequest || editor.hidden) return;
            if (this.selectedAttachments.length >= 10 || this.selectedAttachments.reduce((total, item) => total + Number(item.size || 0), 0) + uploaded.size > 10 * 1024 * 1024) throw new Error('The file is in your library, but the current draft attachment limit has been reached.');
            this.selectedAttachments.push(uploaded); this.renderAttachments();
        } catch (error) {
            if (generation === this.contentEditorRequest && !editor.hidden) Espo.Ui.error(error?.message || 'The attachment could not be uploaded.');
        } finally {
            this.attachmentUploading = false;
            saveButton.disabled = false;
            button.removeAttribute('aria-busy');
            if (generation === this.contentEditorRequest && !editor.hidden) this.renderAttachments();
        }
    }
    async installContentEditor() {
        const request = this.contentEditorRequest = (this.contentEditorRequest || 0) + 1;
        const form = this.element.querySelector('[data-email-form]');
        const source = form.elements.bodyHtml;
        let holder = form.querySelector('[data-rich-content]');
        if (!holder) {
            holder = document.createElement('div');
            holder.dataset.richContent = '';
            holder.className = 'nexa-email-rich-content';
            form.querySelector('.nexa-email-code').before(holder);
            const toggle = document.createElement('button');
            toggle.type = 'button'; toggle.className = 'btn btn-default';
            toggle.dataset.action = 'content-mode'; toggle.disabled = true;
            toggle.innerHTML = '<span class="fas fa-code" aria-hidden="true"></span> HTML source';
            holder.before(toggle);
        }
        try {
            const model = await this.getModelFactory().create('EmailTemplate');
            if (request !== this.contentEditorRequest) return;
            model.set({body: source.value, isHtml: true});
            const editor = await this.createView('marketingContentEditor', 'views/fields/wysiwyg', {
                fullSelector: '[data-rich-content]', model, name: 'body', mode: 'edit',
                params: {height: 360, minHeight: 240, toolbar: [
                    ['style', ['style', 'bold', 'italic', 'underline', 'clear']],
                    ['text', ['fontsize', 'color']], ['para', ['ul', 'ol', 'paragraph']],
                    ['insert', ['espoTable', 'espoLink', 'hr']], ['history', ['undo', 'redo']],
                ]},
            });
            if (request !== this.contentEditorRequest) { editor.remove(); return; }
            this.contentEditor = editor; this.contentModel = model;
            await editor.render();
            if (request !== this.contentEditorRequest) return;
            this.listenTo(editor, 'change', () => {
                if (this.contentSourceMode) return;
                this.contentRichDirty = true;
                this.syncRichContent(); this.contentChanged();
            });
            this.contentSourceMode = false;
            this.contentRichDirty = false;
            source.required = false;
            form.querySelector('.nexa-email-code').hidden = true;
            holder.hidden = false;
            const toggle = form.querySelector('[data-action="content-mode"]');
            toggle.disabled = false; toggle.setAttribute('aria-pressed', 'false');
            if (!this.contentEditorRemovalHook) {
                this.contentEditorRemovalHook = true;
                this.once('remove', () => this.destroyContentEditor());
            }
        } catch (error) {
            if (request !== this.contentEditorRequest) return;
            holder.hidden = true;
            source.required = true; form.querySelector('.nexa-email-code').hidden = false;
            Espo.Ui.error('The visual editor could not be loaded. HTML source editing is still available.');
        }
    }
    syncRichContent() {
        if (!this.contentEditor || this.contentSourceMode || !this.contentRichDirty) return;
        this.contentEditor.fetchToModel();
        this.element.querySelector('[name="bodyHtml"]').value = this.contentModel.get('body') || '';
        this.contentRichDirty = false;
    }
    toggleContentMode() {
        const form = this.element.querySelector('[data-email-form]');
        if (!this.contentEditor) return;
        if (!this.contentSourceMode) this.syncRichContent();
        else {
            this.contentRichDirty = false;
            this.contentModel.set('body', form.elements.bodyHtml.value);
        }
        this.contentSourceMode = !this.contentSourceMode;
        form.querySelector('.nexa-email-code').hidden = !this.contentSourceMode;
        form.querySelector('[data-rich-content]').hidden = this.contentSourceMode;
        const toggle = form.querySelector('[data-action="content-mode"]');
        toggle.innerHTML = this.contentSourceMode ? '<span class="fas fa-pen" aria-hidden="true"></span> Visual editor' : '<span class="fas fa-code" aria-hidden="true"></span> HTML source';
        toggle.setAttribute('aria-pressed', String(this.contentSourceMode));
        this.contentChanged();
    }
    destroyContentEditor() {
        this.closeConditionalContent();
        this.contentEditorRequest = (this.contentEditorRequest || 0) + 1;
        if (this.contentEditor) this.stopListening(this.contentEditor);
        this.clearView?.('marketingContentEditor');
        this.contentEditor = null; this.contentModel = null; this.contentSourceMode = false;
        this.contentRichDirty = false;
        const form = this.element?.querySelector('[data-email-form]');
        if (!form) return;
        form.querySelector('.nexa-email-code').hidden = false;
        form.elements.bodyHtml.required = true;
        const holder = form.querySelector('[data-rich-content]');
        if (holder) holder.hidden = true;
        const toggle = form.querySelector('[data-action="content-mode"]');
        if (toggle) { toggle.disabled = true; toggle.innerHTML = '<span class="fas fa-code" aria-hidden="true"></span> HTML source'; }
    }
    installDeliveryMode(item) {
        const form = this.element.querySelector('[data-email-form]');
        if (!form.elements.deliveryMode) {
            form.elements.campaignId.closest('label').insertAdjacentHTML('beforebegin', '<label><span>Delivery</span><select class="form-control" name="deliveryMode"><option value="campaign">Campaign audience</option><option value="single_send">One Contact</option></select></label>');
        }
        const mode = item?.deliveryMode || 'campaign';
        // Retain legacy API-created modes without offering unimplemented transactional rules.
        form.elements.deliveryMode.querySelector('[data-existing-mode]')?.remove();
        if (!['campaign','single_send'].includes(mode)) {
            const option = new Option(this.label(mode), mode); option.dataset.existingMode = '';
            form.elements.deliveryMode.add(option);
        }
        form.elements.deliveryMode.value = mode;
        this.updateDeliveryMode();
    }
    updateDeliveryMode() {
        const form = this.element.querySelector('[data-email-form]');
        form.elements.startAt.closest('label').hidden = form.elements.deliveryMode?.value === 'single_send';
    }
    chooseSingleSendContact(event) {
        if (this.singleSendDialog || this.singleSendSelecting) return;
        const trigger = event.currentTarget;
        const item = this.workspace?.emails?.find(row => row.id === trigger.dataset.id);
        if (!item || item.deliveryMode !== 'single_send' || item.lifecycleStatus !== 'approved' || !this.workspace?.permissions?.approve || !this.workspace.deliveryEnabled) return;
        const request = this.singleSendSelection = (this.singleSendSelection || 0) + 1;
        this.singleSendSelecting = true;
        if (!this.singleSendRemovalHook) {
            this.singleSendRemovalHook = true;
            this.once?.('remove', () => { this.singleSendSelection++; this.singleSendSelecting = false; this.closeSingleSend(true); });
        }
        this.createView('singleSendContactSelector', 'views/modals/select-records', {
            scope: 'Contact', multiple: false, createButton: false, headerText: 'Choose email recipient',
        }, view => {
            if (request !== this.singleSendSelection) {view.remove(); return;}
            this.listenToOnce(view, 'remove', () => {if (request === this.singleSendSelection) this.singleSendSelecting = false;});
            this.listenToOnce(view, 'select', model => {
                if (request !== this.singleSendSelection || !this.element?.isConnected) return;
                this.singleSendSelecting = false;
                this.openSingleSend(item, model, trigger);
            });
            view.render();
        });
    }
    openSingleSend(item, contact, trigger) {
        if (this.singleSendDialog || !contact?.id) return;
        const modal = document.createElement('div'); modal.className = 'nexa-marketing-email-editor nexa-conditional-overlay';
        modal.dataset.singleSendDialog = '';
        modal.innerHTML = `<section class="nexa-marketing-email-dialog nexa-conditional-dialog" role="dialog" aria-modal="true" aria-labelledby="nexa-single-send-title"><header><h2 id="nexa-single-send-title">Send to one Contact</h2><button type="button" class="btn btn-icon" data-action="close-single-send" title="Close" aria-label="Close single-send"><span class="fas fa-times" aria-hidden="true"></span></button></header><form data-single-send-form><section><dl class="nexa-single-send-summary"><div><dt>Email</dt><dd>${this.escape(item.name)}</dd></div><div><dt>Recipient</dt><dd>${this.escape(contact.get('name') || contact.id)}<small>${this.escape(contact.get('emailAddress') || '')}</small></dd></div><div><dt>Sender</dt><dd>${this.escape(item.fromAddress)}</dd></div></dl><p data-single-send-error role="alert"></p><p data-single-send-progress role="status" hidden><span class="fas fa-circle-notch fa-spin" aria-hidden="true"></span>Queuing email...</p></section><footer><button type="button" class="btn btn-default" data-action="close-single-send">Cancel</button><button type="submit" class="btn btn-primary"><span class="fas fa-paper-plane" aria-hidden="true"></span>Queue email</button></footer></form></section>`;
        const dialog = this.singleSendDialog = {modal, id:item.id, contactId:contact.id, trigger, busy:false};
        this.element.append(modal);
        modal.addEventListener('keydown', event => {
            if (event.key === 'Escape') {event.preventDefault(); this.closeSingleSend();}
            if (event.key === 'Tab') {
                const controls = [...modal.querySelectorAll('button:not(:disabled)')];
                const index = controls.indexOf(document.activeElement); event.preventDefault();
                controls[(index + (event.shiftKey ? controls.length - 1 : 1)) % controls.length]?.focus();
            }
        });
        dialog.modal.querySelector('[type="submit"]').focus();
    }
    closeSingleSend(force = false) {
        const dialog = this.singleSendDialog;
        if (dialog?.busy && force !== true) return;
        this.singleSendDialog = null;
        dialog?.modal.remove(); if (dialog?.trigger?.isConnected) dialog.trigger.focus();
    }
    async submitSingleSend(event) {
        event.preventDefault();
        const dialog = this.singleSendDialog;
        if (!dialog || dialog.busy) return;
        dialog.busy = true;
        dialog.modal.querySelector('[data-single-send-error]').textContent = '';
        dialog.modal.querySelector('[data-single-send-progress]').hidden = false;
        dialog.modal.querySelectorAll('button').forEach(button => {button.disabled = true;});
        try {
            const result = await Espo.Ajax.postRequest(`Nexa/marketing-emails/${encodeURIComponent(dialog.id)}/single-send`, {contactId:dialog.contactId});
            if (dialog !== this.singleSendDialog) return;
            dialog.busy = false; this.closeSingleSend();
            if (result.replayed) Espo.Ui.success(`Existing email request: ${this.label(result.status)}.`);
            else Espo.Ui.success('Email queued. Delivery results will update after processing.');
            await this.load();
        } catch (error) {
            if (dialog !== this.singleSendDialog) return;
            dialog.modal.querySelector('[data-single-send-error]').textContent = error?.message || 'The request could not be confirmed. Retry with this same email and Contact; it will not create another delivery.';
        } finally {
            if (dialog === this.singleSendDialog) {
                dialog.busy = false; dialog.modal.querySelector('[data-single-send-progress]').hidden = true;
                dialog.modal.querySelectorAll('button').forEach(button => {button.disabled = false;});
                dialog.modal.querySelector('[type="submit"]').focus();
            }
        }
    }
    chooseTestContact(event) {
        const button = event.currentTarget;
        const id = button.dataset.id;
        this.confirm({message: 'Send this approved email to your own email address? This uses one monthly send. Choose a Contact for personalization only.', confirmText: 'Choose Contact'}, () => {
            this.createView('testContactSelector', 'views/modals/select-records', {
                scope: 'Contact', multiple: false, createButton: false, headerText: 'Choose test personalization',
            }, view => {
                this.listenToOnce(view, 'select', async model => {
                    button.disabled = true;
                    Espo.Ui.notify('Submitting test to Postal...');
                    try {
                        const result = await Espo.Ajax.postRequest(`Nexa/marketing-emails/${encodeURIComponent(id)}/test`, {contactId: model.id});
                        Espo.Ui.notify(false);
                        if (['submitted','delivered'].includes(result.status)) Espo.Ui.success(`Postal accepted the test for ${result.recipient}.`);
                        else Espo.Ui.error(result.status === 'failed' ? 'Postal rejected the test. Check delivery settings.' : 'Acceptance is uncertain. Review the held submission before retrying.');
                        await this.load();
                    } catch (error) {
                        Espo.Ui.notify(false);
                        Espo.Ui.error(error?.message || 'The test could not be submitted.');
                    } finally { button.disabled = false; }
                });
                view.render();
            });
        });
    }
    choosePreviewContact() {
        const editor = this.element.querySelector('[data-email-editor]');
        editor.style.display = 'none';
        this.createView('previewContactSelector', 'views/modals/select-records', {
            scope: 'Contact', multiple: false, createButton: false, headerText: 'Choose a preview Contact',
        }, view => {
            this.listenToOnce(view, 'remove', () => { editor.style.display = 'grid'; });
            this.listenToOnce(view, 'select', model => this.previewContact(model));
            view.render();
        });
    }
    async previewContact(model) {
        const form = this.element.querySelector('[data-email-form]');
        const input = {contactId: model.id, subject: form.elements.subject.value, bodyHtml: form.elements.bodyHtml.value};
        const key = JSON.stringify([input.subject, input.bodyHtml]);
        const request = this.previewRequest = (this.previewRequest || 0) + 1;
        Espo.Ui.notify('Rendering preview...');
        try {
            const result = await Espo.Ajax.postRequest('Nexa/marketing-emails/preview', input);
            Espo.Ui.notify(false);
            if (request !== this.previewRequest || this.element.querySelector('[data-email-editor]').hidden) return;
            this.recipientPreview = {...result, key, name: model.get('name') || 'Contact'};
            Espo.Ui.notify(false); this.renderContentPreview();
        } catch (error) {
            Espo.Ui.notify(false); Espo.Ui.error(error?.message || 'The recipient preview could not be rendered.');
        }
    }
    editEmail(event) {
        const item = (this.workspace.emails || []).find(item => item.id === event.currentTarget.dataset.id);
        if (!item) return;
        if (!['draft','review','approved'].includes(item.lifecycleStatus) || !this.workspace.permissions?.edit) return this.openResults(event);
        this.previewRequest = (this.previewRequest || 0) + 1;
        this.recipientPreview = null;
        this.pendingTemplate = null;
        this.templateRequest = (this.templateRequest || 0) + 1;
        this.openEditor(item);
    }
    openEditor(item) { this.destroyContentEditor(); this.current = item || null; this.selectedAttachments = [...(item?.attachments || [])]; this.renderAttachments(); const form = this.element.querySelector('[data-email-form]'); form.reset(); this.installDeliveryMode(item); form.elements.name.value=item?.name||''; form.elements.campaignId.value=item?.campaignId||''; form.elements.subject.value=item?.subject||''; form.elements.preheader.value=item?.preheader||''; form.elements.fromAddress.value=item?.fromAddress||''; form.elements.fromName.value=item?.fromName||''; form.elements.replyToAddress.value=item?.replyToAddress||''; form.elements.replyToName.value=item?.replyToName||''; form.elements.startAt.value=this.inputDateTime(item?.startAt); form.elements.bodyHtml.value=item?.bodyHtml||'<p>Hello {Contact.firstName},</p><p></p><p>Kind regards,<br>{User.name}</p>'; this.syncCampaign(); this.text('[data-editor-title]', item ? `Edit ${item.name}` : 'Create marketing email'); const editor=this.element.querySelector('[data-email-editor]'); editor.hidden=false; editor.style.display='grid'; document.body.classList.add('nexa-modal-open'); this.installContentEditor(); setTimeout(()=>form.elements.name.focus(),0); }
    closeEditor() { this.closeBlockDesigner(); this.destroyContentEditor(); this.previewRequest = (this.previewRequest || 0) + 1; const editor=this.element.querySelector('[data-email-editor]'); editor.hidden=true; editor.style.display='none'; document.body.classList.remove('nexa-modal-open'); }
    async openDelivery() { const editor=this.element.querySelector('[data-delivery-editor]'); editor.hidden=false; editor.style.display='grid'; document.body.classList.add('nexa-modal-open'); Espo.Ui.notify('Loading delivery settings...'); try { this.delivery=await Espo.Ajax.getRequest('Nexa/marketing-delivery/settings'); Espo.Ui.notify(false); this.renderDelivery(); } catch(error){this.closeDelivery();Espo.Ui.notify(false);Espo.Ui.error(error?.message||'Delivery settings could not be loaded.');} }
    closeDelivery() { const editor=this.element.querySelector('[data-delivery-editor]'); editor.hidden=true; editor.style.display='none'; document.body.classList.remove('nexa-modal-open'); }
    renderDelivery() { const provider=this.delivery?.provider||{}; const form=this.element.querySelector('[data-provider-form]'); form.elements.apiBaseUrl.value=provider.apiBaseUrl||''; form.elements.serverName.value=provider.serverName||''; form.elements.apiKey.value=''; form.elements.apiKey.required=!provider.hasCredential; form.elements.monthlySendLimit.value=Number(provider.monthlySendLimit||0); this.text('[data-credential-help]',provider.hasCredential?'An encrypted API key is configured. Leave blank to keep it.':'The key is encrypted and is never returned to the browser.'); const domains=this.delivery?.domains||[]; this.element.querySelector('[data-domain-list]').innerHTML=domains.length?domains.map(item=>`<article class="nexa-domain-card"><div><strong>${this.escape(item.domainName)}</strong><small>Ownership ${this.escape(item.ownershipStatus)} / SPF ${this.escape(item.spfStatus)} / DKIM ${this.escape(item.dkimStatus)} / DMARC ${this.escape(item.dmarcStatus)} / Return path ${this.escape(item.returnPathStatus)}</small><code>TXT @ nexa-verification=${this.escape(item.verificationToken)}</code></div><button class="btn btn-default" data-action="verify-domain" data-id="${this.escape(item.id)}"><span class="fas fa-sync"></span>Check DNS</button></article>`).join(''):'<p class="text-muted">No sending domain has been added.</p>'; }
    async saveProvider(event) { event.preventDefault(); const form=event.currentTarget; const payload={apiBaseUrl:form.elements.apiBaseUrl.value.trim(),serverName:form.elements.serverName.value.trim(),apiKey:form.elements.apiKey.value,monthlySendLimit:Number(form.elements.monthlySendLimit.value||0)}; Espo.Ui.notify('Saving provider...'); try{this.delivery=await Espo.Ajax.putRequest('Nexa/marketing-delivery/provider',payload);Espo.Ui.success('Postal provider saved securely.');this.renderDelivery();await this.load();}catch(error){Espo.Ui.notify(false);Espo.Ui.error(error?.message||'Provider settings could not be saved.');} }
    installCallbackSetup() { const section=this.element.querySelector('[data-provider-form] section'); if(!section||section.querySelector('[data-callback-setup]'))return; section.insertAdjacentHTML('beforeend','<div class="nexa-callback-setup" data-callback-setup hidden><strong>Postal webhook URL</strong><p>Copy this URL into Postal webhook settings. Treat it as a secret.</p><div><input class="form-control" data-callback-url readonly><button class="btn btn-default" data-action="copy-callback" type="button" title="Copy webhook URL"><span class="fas fa-copy"></span></button></div></div><div class="nexa-callback-existing" data-callback-existing hidden><span class="fas fa-check-circle"></span><span>A webhook token is configured.</span><button class="btn btn-link" data-action="rotate-callback" type="button">Rotate URL</button></div>'); }
    renderCallback() { const setup=this.element?.querySelector('[data-callback-setup]'); const existing=this.element?.querySelector('[data-callback-existing]'); if(!setup||!existing)return; if(this.delivery?.callbackToken){setup.hidden=false;existing.hidden=true;this.element.querySelector('[data-callback-url]').value=this.callbackUrl(this.delivery.callbackToken);return;} setup.hidden=true;existing.hidden=!this.delivery?.provider?.callbackTokenPrefix; }
    async copyCallback() { const input=this.element.querySelector('[data-callback-url]'); if(!input?.value)return; try{await navigator.clipboard.writeText(input.value);}catch(error){input.select();document.execCommand('copy');} Espo.Ui.success('Webhook URL copied.'); }
    rotateCallback() { this.confirm({message:'Rotate the Postal webhook URL? The current URL will stop working immediately.',confirmText:'Rotate URL'},async()=>{Espo.Ui.notify('Rotating webhook URL...');try{this.delivery=await Espo.Ajax.postRequest('Nexa/marketing-delivery/callback/rotate',{});Espo.Ui.success('Webhook URL rotated. Update it in Postal now.');}catch(error){Espo.Ui.notify(false);Espo.Ui.error(error?.message||'Webhook URL could not be rotated.');}}); }
    callbackUrl(token) { const configured=String(this.getConfig().get('siteUrl')||'').replace(/\/$/,''); const base=configured||`${window.location.origin}${window.location.pathname.split('/w/')[0]}`; return `${base}/api/v1/Nexa/public/marketing-delivery/postal/${encodeURIComponent(token)}`; }
    async saveDomain(event) { event.preventDefault(); const form=event.currentTarget; const payload=Object.fromEntries(['domainName','spfInclude','dkimHost','dkimValue','returnPathHost','returnPathValue'].map(name=>[name,form.elements[name].value.trim()])); Espo.Ui.notify('Saving sending domain...'); try{this.delivery=await Espo.Ajax.postRequest('Nexa/marketing-delivery/domains',payload);Espo.Ui.success('Sending domain saved. Add the DNS records, then check DNS.');form.reset();this.renderDelivery();await this.load();}catch(error){Espo.Ui.notify(false);Espo.Ui.error(error?.message||'Sending domain could not be saved.');} }
    async verifyDomain(event) { const id=event.currentTarget.dataset.id; Espo.Ui.notify('Checking public DNS...'); try{this.delivery=await Espo.Ajax.postRequest(`Nexa/marketing-delivery/domains/${encodeURIComponent(id)}/verify`,{});Espo.Ui.success(this.delivery.readiness?.ready?'Delivery prerequisites are ready.':'DNS check completed. Missing records remain listed.');this.renderDelivery();await this.load();}catch(error){Espo.Ui.notify(false);Espo.Ui.error(error?.message||'DNS could not be checked.');} }
    syncCampaign() { const form=this.element.querySelector('[data-email-form]'); const campaign=(this.workspace?.campaigns||[]).find(item=>item.id===form.elements.campaignId.value); form.elements.purposeId.value=campaign?.purposeId||''; this.text('[data-campaign-purpose]', campaign ? `${campaign.purposeName || 'Email consent'} / ${Number(campaign.eligibleCount || 0)} eligible / ${Number(campaign.suppressedCount || 0)} suppressed` : 'Choose the governed campaign audience for this email.'); this.renderContentPreview(); }
    payload() { this.syncRichContent(); const form=this.element.querySelector('[data-email-form]'); return {name:form.elements.name.value.trim(),campaignId:form.elements.campaignId.value,purposeId:form.elements.purposeId.value,subject:form.elements.subject.value.trim(),preheader:form.elements.preheader.value.trim(),fromAddress:form.elements.fromAddress.value.trim(),fromName:form.elements.fromName.value.trim(),replyToAddress:form.elements.replyToAddress.value.trim(),replyToName:form.elements.replyToName.value.trim(),startAt:form.elements.deliveryMode?.value==='single_send'?null:(form.elements.startAt.value||null),deliveryMode:form.elements.deliveryMode?.value||this.current?.deliveryMode||'campaign',bodyHtml:form.elements.bodyHtml.value,bodyText:'', ...(this.workspace?.permissions?.attachments ? {attachmentsIds:(this.selectedAttachments || []).map(file => file.id)} : {})}; }
    async save(event) { event.preventDefault(); const payload=this.payload(); if (!payload.name||!payload.campaignId||!payload.subject||!payload.fromAddress||!payload.bodyHtml.trim()) return Espo.Ui.error('Complete the required name, campaign, subject, sender and content fields.'); Espo.Ui.notify('Saving marketing email...'); try { if(this.current) await Espo.Ajax.putRequest(`Nexa/marketing-emails/${encodeURIComponent(this.current.id)}`,payload); else await Espo.Ajax.postRequest('Nexa/marketing-emails',payload); Espo.Ui.success('Marketing email draft saved.'); this.closeEditor(); await this.load(); } catch(error){Espo.Ui.notify(false);Espo.Ui.error(error?.message||'The marketing email could not be saved.');} }
    transition(event) { const id=event.currentTarget.dataset.id; const action=event.currentTarget.dataset.transition; const labels={submit:'Submit this email for review?',approve:'Approve this email content?',return:'Return this email to draft?'}; this.confirm({message:labels[action],confirmText:this.label(action)},async()=>{Espo.Ui.notify('Updating email...');try{await Espo.Ajax.postRequest(`Nexa/marketing-emails/${encodeURIComponent(id)}/transition`,{action});Espo.Ui.success('Marketing email status updated.');await this.load();}catch(error){Espo.Ui.notify(false);Espo.Ui.error(error?.message||'The status could not be updated.');}}); }
    scheduleEmail(event) { const id=event.currentTarget.dataset.id; this.confirm({message:'Schedule this approved email for its proposed send time? Eligibility and monthly capacity will be checked again before delivery.',confirmText:'Schedule email'},async()=>{Espo.Ui.notify('Scheduling email...');try{const result=await Espo.Ajax.postRequest(`Nexa/marketing-emails/${encodeURIComponent(id)}/schedule`,{});Espo.Ui.success(`Email scheduled for ${this.dateTime(result.scheduledAt)} with ${Number(result.eligibleCount)} eligible recipients.`);await this.load();}catch(error){Espo.Ui.notify(false);Espo.Ui.error(error?.message||'The email could not be scheduled.');}}); }
    inputDateTime(value) { if(!value)return''; return String(value).replace(' ','T').slice(0,16); }
    dateTime(value) { if(!value)return'Not updated'; const parsed=new Date(String(value).replace(' ','T')); return Number.isNaN(parsed.getTime())?String(value):parsed.toLocaleString(); }
    label(value) { return String(value||'').replace(/_/g,' ').replace(/\b\w/g,letter=>letter.toUpperCase()); }
    text(selector,value) { const node=this.element.querySelector(selector); if(node)node.textContent=String(value??''); }
    escape(value) { const node=document.createElement('div'); node.textContent=String(value??''); return node.innerHTML; }
});
