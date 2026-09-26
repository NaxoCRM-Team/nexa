define('custom:views/consent/workspace', ['view'], Dep => class extends Dep {
    template = 'custom:consent/workspace';

    events = {
        'click [data-action="reload"]': 'load',
        'click [data-action="add-purpose"]': 'openPurpose',
        'click [data-action="edit-purpose"]': 'editPurpose',
        'click [data-action="close-purpose"]': 'closePurpose',
        'submit [data-purpose-form]': 'savePurpose',
        'input [data-contact-search]': 'queueContactSearch',
        'click [data-action="select-contact"]': 'selectContact',
        'click [data-action="clear-contact"]': 'clearContact',
        'change [name="purposeId"]': 'purposeChanged',
        'change [name="status"]': 'updateEvidenceRequirement',
        'change [name="source"]': 'updateEvidenceRequirement',
        'submit [data-decision-form]': 'saveDecision',
        'input [data-history-search]': 'renderHistory',
        'change [data-history-purpose]': 'renderHistory',
        'change [data-history-channel]': 'renderHistory',
        'change [data-history-status]': 'renderHistory',
        'change [data-history-voided]': 'renderHistory',
        'click [data-history-sort]': 'sortHistory',
        'click [data-action="correct-decision"]': 'correctDecision',
        'click [data-action="void-decision"]': 'openVoidDecision',
        'click [data-action="close-void"]': 'closeVoidDecision',
        'submit [data-void-form]': 'voidDecision',
    };

    setup() {
        this.setPageTitle('Consent & Privacy');
        this.workspace = null;
        this.selectedContact = null;
        this.searchTimer = null;
        this.correctsEventId = null;
        this.historySort = {field: 'occurredAt', direction: 'desc'};
    }

    afterRender() {
        super.afterRender();
        document.body.classList.add('nexa-consent-page');
        ['[data-contact-results]', '[data-selected-contact]', '[data-decision-form]', '[data-purpose-modal]', '[data-void-modal]'].forEach(selector => {
            const node = this.element.querySelector(selector);
            if (node) node.style.display = 'none';
        });
        this.once('remove', () => {
            document.body.classList.remove('nexa-consent-page');
            window.clearTimeout(this.searchTimer);
        });
        this.load();
    }

    async load() {
        this.setState('loading');
        try {
            this.workspace = await Espo.Ajax.getRequest('Nexa/consent/workspace');
            this.renderWorkspace();
            this.setState('ready');
        } catch (error) {
            this.setState('error');
            Espo.Ui.error(error?.message || 'Consent governance could not be loaded.');
        }
    }

    setState(state) {
        this.element?.querySelectorAll('[data-consent-state]').forEach(node => {
            node.hidden = node.dataset.consentState !== state;
            node.style.display = node.hidden ? 'none' : '';
        });
    }

    renderWorkspace() {
        Object.entries(this.workspace.summary || {}).forEach(([key, value]) => {
            const node = this.element.querySelector(`[data-summary="${key}"]`);
            if (node) node.textContent = Number(value || 0).toLocaleString();
        });
        this.element.querySelector('[data-purpose-list]').innerHTML = (this.workspace.purposes || []).map(purpose => this.purposeCard(purpose)).join('') || '<p class="nexa-empty-state">No communication purposes have been configured.</p>';
        this.populateHistoryFilters();
        this.renderHistory();
        this.populateDecisionOptions();
    }

    purposeCard(purpose) {
        const channels = (purpose.channels || []).map(channel => `<span>${this.escape(this.channelLabel(channel))}</span>`).join('');
        return `<article class="nexa-purpose-card ${purpose.isActive ? '' : 'is-inactive'}">
            <div class="nexa-purpose-icon"><span class="fas ${purpose.purposeKey === 'marketing_communications' ? 'fa-bullhorn' : purpose.purposeKey === 'customer_service' ? 'fa-headset' : 'fa-handshake'}" aria-hidden="true"></span></div>
            <div><div class="nexa-purpose-title"><h3>${this.escape(purpose.name)}</h3>${purpose.isSystem ? '<span>Core</span>' : ''}${purpose.isActive ? '' : '<span>Archived</span>'}</div><p>${this.escape(purpose.description || 'No description recorded.')}</p><div class="nexa-purpose-channels">${channels}</div><small>${this.escape(this.basisLabel(purpose.defaultLegalBasis))} &middot; Policy ${this.escape(purpose.policyVersion)}</small></div>
            <button class="btn btn-icon" type="button" data-action="edit-purpose" data-id="${this.escape(purpose.id)}" title="Edit ${this.escape(purpose.name)}"><span class="fas fa-pen" aria-hidden="true"></span></button>
        </article>`;
    }

    populateHistoryFilters() {
        const purpose = this.element.querySelector('[data-history-purpose]');
        const channel = this.element.querySelector('[data-history-channel]');
        const purposeValue = purpose.value;
        const channelValue = channel.value;
        purpose.innerHTML = '<option value="">All purposes</option>' + (this.workspace.purposes || []).map(item => `<option value="${this.escape(item.id)}">${this.escape(item.name)}</option>`).join('');
        channel.innerHTML = '<option value="">All channels</option>' + (this.workspace.channels || []).map(item => `<option value="${this.escape(item)}">${this.escape(this.channelLabel(item))}</option>`).join('');
        purpose.value = purposeValue;
        channel.value = channelValue;
    }

    renderHistory() {
        const body = this.element.querySelector('[data-consent-history]');
        const query = (this.element.querySelector('[data-history-search]')?.value || '').trim().toLowerCase();
        const purpose = this.element.querySelector('[data-history-purpose]')?.value || '';
        const channel = this.element.querySelector('[data-history-channel]')?.value || '';
        const status = this.element.querySelector('[data-history-status]')?.value || '';
        const showVoided = Boolean(this.element.querySelector('[data-history-voided]')?.checked);
        const events = (this.workspace.recentEvents || []).filter(item => {
            const searchable = `${item.contactName} ${item.purposeName} ${item.actorName} ${item.evidenceNote || ''}`.toLowerCase();
            return (!query || searchable.includes(query)) && (!purpose || item.purposeId === purpose) &&
                (!channel || item.channel === channel) && (!status || item.status === status) && (showVoided || !item.voidedAt);
        }).sort((left, right) => {
            const a = String(left[this.historySort.field] || '').toLowerCase();
            const b = String(right[this.historySort.field] || '').toLowerCase();
            return a.localeCompare(b) * (this.historySort.direction === 'asc' ? 1 : -1);
        });
        this.element.querySelector('[data-history-count]').textContent = `${events.length.toLocaleString()} ${events.length === 1 ? 'decision' : 'decisions'}`;
        body.innerHTML = events.map(item => {
            const state = item.voidedAt ? '<span class="nexa-audit-state is-voided">Voided</span>' : item.supersededByEventId ? '<span class="nexa-audit-state">Corrected</span>' : '';
            const correct = item.supersededByEventId ? '' : `<button type="button" data-action="correct-decision" data-id="${this.escape(item.id)}"><span class="fas fa-pen"></span>Correct</button>`;
            const actions = item.voidedAt ? '' : `<div class="nexa-audit-actions">${correct}<button type="button" data-action="void-decision" data-id="${this.escape(item.id)}"><span class="fas fa-ban"></span>Void</button></div>`;
            return `<tr class="${item.voidedAt ? 'is-voided' : ''}"><td><a href="#Contact/view/${this.escape(item.contactId)}">${this.escape(item.contactName)}</a></td><td>${this.escape(item.purposeName)}${state}</td><td>${this.escape(this.channelLabel(item.channel))}</td><td><span class="nexa-consent-status is-${this.escape(item.status)}">${this.escape(this.statusLabel(item.status))}</span></td><td>${this.escape(this.sourceLabel(item.source))}</td><td>${this.escape(item.actorName)}</td><td>${this.escape(this.formatDate(item.occurredAt))}</td><td>${actions}</td></tr>`;
        }).join('') || '<tr><td colspan="8" class="nexa-empty-state">No consent decisions match these filters.</td></tr>';
    }

    sortHistory(event) {
        const field = event.currentTarget.dataset.historySort;
        this.historySort = {field, direction: this.historySort.field === field && this.historySort.direction === 'asc' ? 'desc' : 'asc'};
        this.renderHistory();
    }

    populateDecisionOptions() {
        const form = this.element.querySelector('[data-decision-form]');
        if (!form) return;
        form.elements.purposeId.innerHTML = (this.workspace.purposes || []).filter(item => item.isActive).map(item => `<option value="${this.escape(item.id)}">${this.escape(item.name)}</option>`).join('');
        form.elements.legalBasis.innerHTML = (this.workspace.legalBases || []).map(item => `<option value="${this.escape(item)}">${this.escape(this.basisLabel(item))}</option>`).join('');
        this.purposeChanged();
        this.updateEvidenceRequirement();
    }

    purposeChanged() {
        const form = this.element.querySelector('[data-decision-form]');
        if (!form) return;
        const purpose = (this.workspace.purposes || []).find(item => item.id === form.elements.purposeId.value) || (this.workspace.purposes || []).find(item => item.isActive);
        if (!purpose) return;
        form.elements.channel.innerHTML = (purpose.channels || []).map(channel => `<option value="${this.escape(channel)}">${this.escape(this.channelLabel(channel))}</option>`).join('');
        if (purpose.defaultLegalBasis) form.elements.legalBasis.value = purpose.defaultLegalBasis;
        this.updateEvidenceRequirement();
    }

    queueContactSearch(event) {
        window.clearTimeout(this.searchTimer);
        const query = event.currentTarget.value.trim();
        if (query.length < 2) {
            this.hideContactResults();
            return;
        }
        this.searchTimer = window.setTimeout(() => this.searchContacts(query), 220);
    }

    async searchContacts(query) {
        const host = this.element.querySelector('[data-contact-results]');
        host.hidden = false; host.style.display = ''; host.innerHTML = '<p><span class="fas fa-circle-notch fa-spin"></span> Searching contacts...</p>';
        try {
            const response = await Espo.Ajax.getRequest('Contact', {maxSize: 10, textFilter: query, select: 'id,name,emailAddress,marketingStatus,doNotContact,profileImageId'});
            host.innerHTML = (response.list || []).map(contact => `<button type="button" data-action="select-contact" data-id="${this.escape(contact.id)}" data-name="${this.escape(contact.name || 'Contact')}" data-email="${this.escape(contact.emailAddress || '')}" data-profile-image-id="${this.escape(contact.profileImageId || '')}"><span class="nexa-contact-avatar" data-contact-avatar data-profile-image-id="${this.escape(contact.profileImageId || '')}">${this.escape((contact.name || 'C').charAt(0).toUpperCase())}</span><span><strong>${this.escape(contact.name || 'Contact')}</strong><small>${this.escape(contact.emailAddress || 'No email address')}</small></span><i class="fas fa-chevron-right" aria-hidden="true"></i></button>`).join('') || '<p>No matching Contacts found in this tenant.</p>';
            this.loadContactAvatars(host);
        } catch (error) {
            host.innerHTML = '<p>Contacts could not be searched.</p>';
        }
    }

    selectContact(event) {
        this.selectedContact = {id: event.currentTarget.dataset.id, name: event.currentTarget.dataset.name, email: event.currentTarget.dataset.email, profileImageId: event.currentTarget.dataset.profileImageId};
        this.showSelectedContact();
    }

    showSelectedContact() {
        const selected = this.element.querySelector('[data-selected-contact]');
        selected.innerHTML = `<span class="nexa-contact-avatar" data-contact-avatar data-profile-image-id="${this.escape(this.selectedContact.profileImageId || '')}">${this.escape(this.selectedContact.name.charAt(0).toUpperCase())}</span><span><strong>${this.escape(this.selectedContact.name)}</strong><small>${this.escape(this.selectedContact.email || 'No email address')}</small></span><button class="btn btn-icon" type="button" data-action="clear-contact" title="Choose another Contact"><span class="fas fa-times"></span></button>`;
        selected.hidden = false; selected.style.display = '';
        this.loadContactAvatars(selected);
        const form = this.element.querySelector('[data-decision-form]'); form.hidden = false; form.style.display = '';
        this.element.querySelector('[data-contact-search]').closest('label').hidden = true;
        this.hideContactResults();
    }

    clearContact() {
        this.selectedContact = null;
        this.correctsEventId = null;
        const selected = this.element.querySelector('[data-selected-contact]'); selected.hidden = true; selected.style.display = 'none';
        const form = this.element.querySelector('[data-decision-form]'); form.hidden = true; form.style.display = 'none'; form.reset();
        const search = this.element.querySelector('[data-contact-search]'); search.value = ''; search.closest('label').hidden = false;
        form.elements.purposeId.disabled = false; form.elements.channel.disabled = false;
        this.element.querySelector('.nexa-decision-panel h2').textContent = 'Record a consent decision';
        this.element.querySelector('[data-decision-form] [type="submit"]').innerHTML = '<span class="fas fa-shield-alt" aria-hidden="true"></span>Record decision';
        this.populateDecisionOptions();
    }

    hideContactResults() {
        const host = this.element.querySelector('[data-contact-results]');
        host.hidden = true; host.style.display = 'none'; host.innerHTML = '';
    }

    async saveDecision(event) {
        event.preventDefault();
        if (!this.selectedContact) return;
        const form = event.currentTarget;
        if (!this.validateDecisionForm(form)) return;
        const button = form.querySelector('[type="submit"]'); button.disabled = true;
        try {
            await Espo.Ajax.postRequest('Nexa/consent/decisions', {
                contactId: this.selectedContact.id,
                purposeId: form.elements.purposeId.value,
                channel: form.elements.channel.value,
                status: form.elements.status.value,
                legalBasis: form.elements.legalBasis.value,
                source: form.elements.source.value,
                expiresAt: form.elements.expiresAt.value || null,
                evidenceNote: form.elements.evidenceNote.value.trim() || null,
                correctsEventId: this.correctsEventId,
            });
            Espo.Ui.success('Consent decision recorded.');
            this.clearContact();
            await this.load();
        } catch (error) {
            if (String(error?.message || '').includes('Describe how consent was obtained')) {
                this.setFieldError(form.elements.evidenceNote, 'Describe how consent was obtained.');
            }
            Espo.Ui.error(error?.message || 'Consent decision could not be recorded.');
        } finally {
            button.disabled = false;
        }
    }

    updateEvidenceRequirement() {
        const form = this.element.querySelector('[data-decision-form]');
        if (!form) return;
        const required = form.elements.status.value === 'granted' && form.elements.source.value === 'manual';
        form.elements.evidenceNote.required = required;
        const marker = form.querySelector('[data-evidence-required]');
        marker.hidden = !required; marker.style.display = required ? '' : 'none';
        form.querySelector('[data-evidence-help]').textContent = required ? 'Required when staff record a granted decision.' : 'Optional supporting context.';
        if (!required) this.setFieldError(form.elements.evidenceNote, '');
    }

    validateDecisionForm(form) {
        let valid = true;
        form.querySelectorAll('[data-field-error]').forEach(node => { node.textContent = ''; });
        form.querySelectorAll('[required]').forEach(field => {
            if (String(field.value || '').trim()) return;
            this.setFieldError(field, 'This field is required.');
            valid = false;
        });
        if (!valid) form.querySelector('.is-invalid')?.focus();
        return valid;
    }

    setFieldError(field, message) {
        field.classList.toggle('is-invalid', Boolean(message));
        let error = field.closest('label')?.querySelector('[data-field-error]');
        if (!error && message) {
            error = document.createElement('em'); error.dataset.fieldError = field.name; error.setAttribute('role', 'alert');
            field.closest('label')?.append(error);
        }
        if (error) error.textContent = message;
    }

    correctDecision(event) {
        const item = (this.workspace.recentEvents || []).find(row => row.id === event.currentTarget.dataset.id);
        if (!item || item.voidedAt || item.supersededByEventId) return;
        this.correctsEventId = item.id;
        this.selectedContact = {id: item.contactId, name: item.contactName, email: '', profileImageId: item.profileImageId};
        this.showSelectedContact();
        const form = this.element.querySelector('[data-decision-form]');
        form.elements.purposeId.value = item.purposeId; this.purposeChanged();
        form.elements.channel.value = item.channel;
        form.elements.purposeId.disabled = true; form.elements.channel.disabled = true;
        form.elements.status.value = item.status;
        form.elements.legalBasis.value = item.legalBasis;
        form.elements.source.value = item.source;
        form.elements.expiresAt.value = item.expiresAt ? String(item.expiresAt).replace(' ', 'T').slice(0, 16) : '';
        form.elements.evidenceNote.value = item.evidenceNote || '';
        this.element.querySelector('.nexa-decision-panel h2').textContent = 'Correct consent decision';
        form.querySelector('[type="submit"]').innerHTML = '<span class="fas fa-check" aria-hidden="true"></span>Save correction';
        this.updateEvidenceRequirement();
        this.element.querySelector('.nexa-decision-panel').scrollIntoView({behavior: 'smooth', block: 'start'});
    }

    openVoidDecision(event) {
        const item = (this.workspace.recentEvents || []).find(row => row.id === event.currentTarget.dataset.id);
        if (!item || item.voidedAt) return;
        const modal = this.element.querySelector('[data-void-modal]');
        modal.innerHTML = `<section role="dialog" aria-modal="true" aria-labelledby="nexa-void-title"><header><div><p>Consent governance</p><h2 id="nexa-void-title">Void consent decision?</h2></div><button class="btn btn-icon" type="button" data-action="close-void" aria-label="Close"><span class="fas fa-times"></span></button></header><form data-void-form data-id="${this.escape(item.id)}" novalidate><p>This keeps the original evidence in the audit trail and recalculates the Contact's current ${this.escape(this.channelLabel(item.channel))} preference.</p><label><span>Reason <b aria-hidden="true">*</b></span><textarea class="form-control" name="reason" rows="3" maxlength="500" required placeholder="Explain why this decision is invalid"></textarea><em data-field-error="reason" role="alert"></em></label><footer><button class="btn btn-danger" type="submit"><span class="fas fa-ban"></span>Void decision</button><button class="btn btn-default" type="button" data-action="close-void">Cancel</button></footer></form></section>`;
        modal.hidden = false; modal.style.display = 'grid'; modal.querySelector('[name="reason"]').focus();
    }

    closeVoidDecision() {
        const modal = this.element.querySelector('[data-void-modal]'); modal.hidden = true; modal.style.display = 'none'; modal.innerHTML = '';
    }

    async voidDecision(event) {
        event.preventDefault();
        const form = event.currentTarget;
        if (!this.validateDecisionForm(form)) return;
        const button = form.querySelector('[type="submit"]'); button.disabled = true;
        try {
            await Espo.Ajax.postRequest(`Nexa/consent/decisions/${encodeURIComponent(form.dataset.id)}/void`, {reason: form.elements.reason.value.trim()});
            this.closeVoidDecision(); Espo.Ui.success('Consent decision voided.'); await this.load();
        } catch (error) {
            this.setFieldError(form.elements.reason, error?.message || 'The consent decision could not be voided.');
        } finally { button.disabled = false; }
    }

    openPurpose(event, purpose = null) {
        event?.preventDefault();
        const modal = this.element.querySelector('[data-purpose-modal]');
        const legalOptions = (this.workspace.legalBases || []).map(item => `<option value="${this.escape(item)}" ${purpose?.defaultLegalBasis === item ? 'selected' : ''}>${this.escape(this.basisLabel(item))}</option>`).join('');
        const channels = (this.workspace.channels || []).map(item => `<label><input type="checkbox" name="channels" value="${this.escape(item)}" ${purpose?.channels?.includes(item) ? 'checked' : ''}>${this.escape(this.channelLabel(item))}</label>`).join('');
        modal.innerHTML = `<section role="dialog" aria-modal="true" aria-labelledby="nexa-purpose-title"><header><div><p>Consent governance</p><h2 id="nexa-purpose-title">${purpose ? 'Edit communication purpose' : 'Add communication purpose'}</h2></div><button class="btn btn-icon" type="button" data-action="close-purpose" aria-label="Close"><span class="fas fa-times"></span></button></header><form data-purpose-form><input type="hidden" name="id" value="${this.escape(purpose?.id || '')}"><label><span>Purpose name</span><input class="form-control" name="name" maxlength="120" required value="${this.escape(purpose?.name || '')}"></label><label><span>Description</span><textarea class="form-control" name="description" rows="3" maxlength="1000">${this.escape(purpose?.description || '')}</textarea></label><label><span>Default legal basis</span><select class="form-control" name="defaultLegalBasis" required>${legalOptions}</select></label><fieldset><legend>Available channels</legend><div class="nexa-channel-checks">${channels}</div></fieldset><div class="nexa-form-grid"><label><span>Policy version</span><input class="form-control" name="policyVersion" maxlength="40" required value="${this.escape(purpose?.policyVersion || '1.0')}"></label><label><span>Privacy notice URL <small>Optional</small></span><input class="form-control" name="privacyNoticeUrl" type="url" maxlength="500" value="${this.escape(purpose?.privacyNoticeUrl || '')}"></label></div><footer><button class="btn btn-primary" type="submit"><span class="fas fa-save"></span>Save purpose</button><button class="btn btn-default" type="button" data-action="close-purpose">Cancel</button></footer></form></section>`;
        modal.hidden = false; modal.style.display = 'grid'; modal.querySelector('[name="name"]')?.focus();
    }

    editPurpose(event) {
        const purpose = (this.workspace.purposes || []).find(item => item.id === event.currentTarget.dataset.id);
        if (purpose) this.openPurpose(event, purpose);
    }

    closePurpose() {
        const modal = this.element.querySelector('[data-purpose-modal]'); modal.hidden = true; modal.style.display = 'none'; modal.innerHTML = '';
    }

    async savePurpose(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const channels = [...form.querySelectorAll('[name="channels"]:checked')].map(input => input.value);
        const button = form.querySelector('[type="submit"]'); button.disabled = true;
        try {
            await Espo.Ajax.postRequest('Nexa/consent/purposes', {
                id: form.elements.id.value || null, name: form.elements.name.value.trim(),
                description: form.elements.description.value.trim(), defaultLegalBasis: form.elements.defaultLegalBasis.value,
                channels, policyVersion: form.elements.policyVersion.value.trim(), privacyNoticeUrl: form.elements.privacyNoticeUrl.value.trim(),
            });
            this.closePurpose(); Espo.Ui.success('Communication purpose saved.'); await this.load();
        } catch (error) {
            Espo.Ui.error(error?.message || 'Communication purpose could not be saved.');
        } finally { button.disabled = false; }
    }

    channelLabel(value) { return {email:'Email',phone:'Phone',sms:'SMS',whatsapp:'WhatsApp',linkedin:'LinkedIn',postal:'Postal mail',live_chat:'Live chat'}[value] || value; }
    statusLabel(value) { return {granted:'Granted',denied:'Denied',withdrawn:'Withdrawn',not_required:'Not required'}[value] || value; }
    sourceLabel(value) { return {manual:'Staff',form:'Form',import:'Import',api:'Connected system',preference_center:'Preference centre',system:'System'}[value] || value; }
    basisLabel(value) { return {LegitimateInterestLead:'Legitimate interest - lead',LegitimateInterestCustomer:'Legitimate interest - customer',LegitimateInterestOther:'Legitimate interest - other',PerformanceOfContract:'Performance of a contract',FreelyGivenConsent:'Freely given consent',NotApplicable:'Not applicable'}[value] || value || 'No legal basis'; }
    async loadContactAvatars(root) {
        await Promise.all([...root.querySelectorAll('[data-contact-avatar][data-profile-image-id]')].map(async avatar => {
            const imageId = avatar.dataset.profileImageId;
            if (!imageId) return;
            try {
                const payload = await Espo.Ajax.getRequest(`Nexa/contact-profile-image/${encodeURIComponent(imageId)}`);
                if (!payload?.data || !payload?.mimeType || avatar.dataset.profileImageId !== imageId) return;
                const image = document.createElement('img');
                image.src = `data:${payload.mimeType};base64,${payload.data}`;
                image.alt = '';
                avatar.replaceChildren(image);
            } catch (error) {
                // Keep the initial when the image was removed or is not accessible.
            }
        }));
    }

    formatDate(value) { if (!value) return 'Not recorded'; return this.getDateTime().toDisplay(value); }
    escape(value) { const node = document.createElement('div'); node.textContent = String(value ?? ''); return node.innerHTML; }
});
